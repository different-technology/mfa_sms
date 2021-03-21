# TYPO3 Extension ``mfa_sms``

This extension adds the SMS MFA provider to TYPO3, using the new MFA API, available since TYPO3 v11.1.

Blog post about SMS as MFA: [https://markus-code.com/2021/03/sms-two-factor-authentication-for-typo3/](https://markus-code.com/2021/03/sms-two-factor-authentication-for-typo3/)

**Note**: Since the TYPO3 MFA API is still experimental, changes in upcoming releases
are to be expected.

## Installation

You can install the extension via composer ``composer require different-technology/mfa-sms`` or via [TYPO3 extension repository](https://extensions.typo3.org/extension/mfa_sms/).

## About SMS MFA

The SMS multi-factor authentication creates an authentication code and sends it to the users mobile phone.
After entering the password, the user has to enter the received authentication code to login to TYPO3.

How to use this provider:

1. Navigate to the MFA module in the TYPO3 backend and click on "Setup"
2. Enter your mobile phone number
3. Submit the form to activate the MFA provider

## Supported SMS providers

Before using the SMS MFA provider, you have to setup an SMS provider.
Navigate to the extension configuration in the TYPO3 backend and enter the DSN of your SMS provider.


### AWS SNS

This extension provides an adapter to use AWS SNS as SMS provider.
Please setup your AWS account and your IAM user/role and use the following configuration:
``sns+https://MY_ACCESS_KEY:MY_URL_ENCODED_SECRET@default?region=eu-west-1``

Please make sure your access key and secret is URL encoded.<br>
The connection to the AWS API is based on a very simple implementation to avoid using the enormous AWS SDK.

### Symfony SMS channel

This extension provides all Symfony SMS channels as SMS providers.
You can find them here: [https://symfony.com/doc/current/notifier.html#sms-channel](https://symfony.com/doc/current/notifier.html#sms-channel)

Please make sure to install the package first, before using the SMS channel.

For example ``composer require symfony/twilio-notifier`` and configure the DSN ``twilio://SID:TOKEN@default?from=FROM``
