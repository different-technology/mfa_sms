<?php

declare(strict_types=1);

namespace DifferentTechnology\MfaSms\Mfa;

use DifferentTechnology\MfaSms\Sms\TransportFactory;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\SmsMessage;
use TYPO3\CMS\Core\Authentication\Mfa\MfaProviderInterface;
use TYPO3\CMS\Core\Authentication\Mfa\MfaProviderPropertyManager;
use TYPO3\CMS\Core\Authentication\Mfa\MfaViewType;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Class SmsProvider
 */
class SmsProvider implements MfaProviderInterface
{
    protected Context $context;
    protected ResponseFactory $responseFactory;
    protected array $extensionConfiguration;

    /**
     * SmsProvider constructor.
     * @param Context $context
     * @param ResponseFactory $responseFactory
     * @param ExtensionConfiguration $extensionConfiguration
     * @throws Exception
     */
    public function __construct(Context $context, ResponseFactory $responseFactory, ExtensionConfiguration $extensionConfiguration)
    {
        $this->context = $context;
        $this->responseFactory = $responseFactory;
        $this->extensionConfiguration = $extensionConfiguration->get('mfa_sms');
    }

    /**
     * @param ServerRequestInterface $request
     * @return bool
     */
    public function canProcess(ServerRequestInterface $request): bool
    {
        return $this->isDsnValid();
    }

    /**
     * Evaluate if the provider is activated
     *
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function isActive(MfaProviderPropertyManager $propertyManager): bool
    {
        return (bool)$propertyManager->getProperty('active');
    }

    /**
     * Evaluate if the provider is temporarily locked
     *
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function isLocked(MfaProviderPropertyManager $propertyManager): bool
    {
        $attempts = (int)$propertyManager->getProperty('attempts', 0);

        // Assume the provider is locked in case the maximum attempts are exceeded.
        // A provider however can only be locked if set up - an entry exists in database.
        return $propertyManager->hasProviderEntry() && $attempts >= $this->getMaxAttempts();
    }

    /**
     * Initialize view and forward to the appropriate implementation
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @param string $type
     * @return ResponseInterface
     * @throws TransportExceptionInterface
     */
    public function handleRequest(
        ServerRequestInterface $request,
        MfaProviderPropertyManager $propertyManager,
        string $type
    ): ResponseInterface {
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplateRootPaths(['EXT:mfa_sms/Resources/Private/Templates/Mfa']);
        switch ($type) {
            case MfaViewType::SETUP:
            case MfaViewType::EDIT:
                $this->prepareEditView($view, $propertyManager);
                break;
            case MfaViewType::AUTH:
                $this->prepareAuthView($request, $view, $propertyManager);
                break;
        }
        $response = $this->responseFactory->createResponse();
        $response->getBody()->write($view->assign('providerIdentifier', $propertyManager->getIdentifier())->render());
        return $response;
    }

    /**
     * Verify the given auth code
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     * @throws AspectNotFoundException
     */
    public function verify(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if (!$this->isActive($propertyManager) || $this->isLocked($propertyManager)) {
            // Can not verify an inactive or locked provider
            return false;
        }

        $authCodeInput = trim((string)($request->getQueryParams()['authCode'] ?? $request->getParsedBody()['authCode'] ?? ''));
        $properties = $propertyManager->getProperties();

        if ($authCodeInput !== $properties['authCode']) {
            $properties['attempts'] = (int)$properties['attempts'] ?? 0;
            $properties['attempts']++;
            $propertyManager->updateProperties($properties);
            return false;
        }

        $properties['authCode'] = '';
        $properties['attempts'] = 0;
        $properties['lastUsed'] = $this->context->getPropertyFromAspect('date', 'timestamp');

        return $propertyManager->updateProperties($properties);
    }

    /**
     * Activate the provider
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function activate(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        return $this->update($request, $propertyManager);
    }

    /**
     * Handle the unlock action by resetting the attempts provider property
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function unlock(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if (!$this->isActive($propertyManager) || !$this->isLocked($propertyManager)) {
            return false;
        }
        return $propertyManager->updateProperties(['attempts' => 0]);
    }

    /**
     * Handle the deactivate action
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function deactivate(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if (!$this->isActive($propertyManager)) {
            return false;
        }
        return $propertyManager->updateProperties(['active' => false]);
    }

    /**
     * Update the provider data
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function update(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if (!$this->canProcess($request)) {
            // Return since the request can not be processed by this provider
            return false;
        }

        $mobileNumber = $request->getParsedBody()['mobileNumber'];
        $mobileNumber = preg_replace('/[^+0-9]/m', '', $mobileNumber);
        if (!$this->isMobileNumberValid($mobileNumber)) {
            return false;
        }

        $properties = [
            'mobileNumber' => $mobileNumber,
            'active' => true
        ];
        return $propertyManager->hasProviderEntry()
            ? $propertyManager->updateProperties($properties)
            : $propertyManager->createProviderEntry($properties);
    }

    /**
     * Set auth code to the properties and send the SMS to the user
     * @param MfaProviderPropertyManager $propertyManager
     * @param bool $resend
     * @throws TransportExceptionInterface
     */
    protected function sendAuthCodeSms(MfaProviderPropertyManager $propertyManager, bool $resend = false): void
    {
        $newAuthCode = false;
        $authCode = $propertyManager->getProperty('authCode');
        if (empty($authCode)) {
            $authCode = $this->generateAuthCode();
            $propertyManager->updateProperties(['authCode' => $authCode]);
            $newAuthCode = true;
        }

        if ($newAuthCode || $resend) {
            $message = sprintf(
                $this->getLanguageService()->sL('LLL:EXT:mfa_sms/Resources/Private/Language/locallang.xlf:sms.message'),
                $authCode
            );
            $smsMessage = GeneralUtility::makeInstance(SmsMessage::class, $propertyManager->getProperty('mobileNumber'), $message);
            $transport = GeneralUtility::makeInstance(TransportFactory::class)->get($this->extensionConfiguration['dsn']);
            $transport->send($smsMessage);
        }
    }

    /**
     * Set the template and assign necessary variables for the edit view
     *
     * @param ViewInterface $view
     * @param MfaProviderPropertyManager $propertyManager
     */
    protected function prepareEditView(ViewInterface $view, MfaProviderPropertyManager $propertyManager): void
    {
        if (!$this->isDsnValid()) {
            $this->showLocalizedFlashMessage('error.dsn.invalid');
        }

        $view->setTemplate('Edit');
        $view->assignMultiple([
            'mobileNumber' => $propertyManager->getProperty('mobileNumber'),
            'lastUsed' => $this->getDateTime($propertyManager->getProperty('lastUsed', 0)),
            'updated' => $this->getDateTime($propertyManager->getProperty('updated', 0)),
        ]);
    }

    /**
     * Set the template and assign necessary variables for the auth view
     *
     * @param ServerRequestInterface $request
     * @param ViewInterface $view
     * @param MfaProviderPropertyManager $propertyManager
     * @throws TransportExceptionInterface
     */
    protected function prepareAuthView(ServerRequestInterface $request, ViewInterface $view, MfaProviderPropertyManager $propertyManager): void
    {
        $queryParams = $request->getQueryParams();
        $resend = !empty($queryParams['resend']) && $queryParams['resend'] === '1';

        $this->sendAuthCodeSms($propertyManager, $resend);
        $view->setTemplate('Auth');
        $view->assignMultiple([
            'isLocked' => $this->isLocked($propertyManager),
            'resendLink' => '?' . http_build_query(array_merge($queryParams, ['resend' => '1'])),
        ]);
    }

    /**
     * Generates an random authentication code with 6 digits
     *
     * @return string
     */
    protected function generateAuthCode(): string
    {
        $code = [];
        $charSet = '0123456789';
        $charSetLength = strlen($charSet) - 1;
        for ($i = 0; $i < 6; $i++) {
            $n = rand(0, $charSetLength);
            $code[] = $charSet[$n];
        }
        shuffle($code);
        return implode($code);
    }

    /**
     * Return the timestamp as local time (date string) by applying the globally configured format
     *
     * @param int $timestamp
     * @return string
     */
    protected function getDateTime(int $timestamp): string
    {
        if ($timestamp === 0) {
            return '';
        }

        return date(
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] . ' ' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'],
            $timestamp
        ) ?: '';
    }

    /**
     * Displays a flash message if the mobile number is invalid
     * @param string $mobileNumber
     * @return bool
     */
    protected function isMobileNumberValid(string $mobileNumber): bool
    {
        $messageKey = null;
        if (empty($mobileNumber)) {
            $messageKey = 'error.mobileNumber.empty';
        } elseif (strpos($mobileNumber, '+') !== 0) {
            $messageKey = 'error.mobileNumber.missingCountryPrefix';
        }
        if ($messageKey !== null) {
            $this->showLocalizedFlashMessage($messageKey);
            return false;
        }
        return true;
    }

    /**
     * Helper to display localized flash messages
     * @param string $messageKey
     */
    protected function showLocalizedFlashMessage(string $messageKey): void
    {
        $languageFilePrefix = 'LLL:EXT:mfa_sms/Resources/Private/Language/locallang.xlf:';
        $errorMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $this->getLanguageService()->sL($languageFilePrefix . $messageKey . '.message'),
            $this->getLanguageService()->sL($languageFilePrefix . $messageKey . '.title'),
            FlashMessage::ERROR,
            true
        );
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $messageQueue = $flashMessageService->getMessageQueueByIdentifier();
        $messageQueue->addMessage($errorMessage);
    }

    /**
     * @return bool
     */
    protected function isDsnValid(): bool
    {
        return !empty($this->extensionConfiguration['dsn']);
    }

    /**
     * Get max attempts to enter the auth code. Value should between 1 and 10.
     * For security reasons fix unsecure configurations.
     * @return int
     */
    protected function getMaxAttempts(): int
    {
        $maxAttempts = (int)$this->extensionConfiguration['maxAttempts'];
        $maxAttempts = $maxAttempts < 1 ? 3 : $maxAttempts;
        $maxAttempts = $maxAttempts > 10 ? 10 : $maxAttempts;
        return $maxAttempts;
    }

    /**
     * @return LanguageService
     */
    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
