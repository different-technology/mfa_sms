<?php

declare(strict_types=1);

namespace DifferentTechnology\MfaSms\Sms;

use Symfony\Component\Notifier\Transport;
use Symfony\Component\Notifier\Transport\Dsn;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class TransportFactory
 *
 * This class creates the SMS Transport provider for the given DSN.
 *
 * @author Markus Hölzle <typo3@markus-hoelzle.de>
 */
class TransportFactory implements SingletonInterface
{
    /**
     * @var EventDispatcherInterface
     */
    protected EventDispatcherInterface $dispatcher;

    /**
     * TransportFactory constructor.
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Returns the Transport for the given DSN.
     *
     * @param string $dsn
     * @return TransportInterface
     */
    public function get(string $dsn): TransportInterface
    {
        $this->validateDsn($dsn);
        $dsnObject = Dsn::fromString($dsn);
        switch ($dsnObject->getScheme()) {
            case AwsSnsTransport::SCHEME:
                return GeneralUtility::makeInstance(
                    AwsSnsTransport::class,
                    $dsnObject->getUser(),
                    $dsnObject->getPassword(),
                    $dsnObject->getOption('region')
                );
            default:
                return Transport::fromDsn($dsn, $this->dispatcher);
        }
    }

    /**
     * @param string $dsn
     */
    protected function validateDsn(string $dsn): void
    {
        if (empty($dsn)) {
            throw new \InvalidArgumentException('Key "dsn" must be set in the extension settings', 1615553438);
        }
    }
}
