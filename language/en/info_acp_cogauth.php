<?php
/**
 *
 * AWS Cognito Authentication. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2018, Mark Gawler
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

$lang = array_merge($lang, array(
	'ACP_COGAUTH_TOKEN_CLEANUP'	=> 'Cognito Access Token cleanup check frequency (minutes)',
	'ACP_COGAUTH_TOKEN_CLEANUP_EXPLAIN' => 'Removal of Cogauth sessions information for expired phpBB sessions and expired AWS Cognito Access tokens. Range one minute to one day (1440 minutes)',
	'ACP_COGAUTH_TITLE'	=> 'AWS Cognito Auth',
	'ACP_COGAUTH_TITLE_CFG'	=> 'IAM Access Key Settings',
	'ACP_COGAUTH_MISC_TITLE' => 'Miscellaneous Settings',
	'ACP_COGAUTH_TITLE_MISC' => 'Miscellaneous Settings',
	'ACP_COGAUTH_TITLE_POOL' => 'User Pool Settings',
	'ACP_COGAUTH_CORE_SETTING_SAVED' => 'Settings have been saved successfully!',
	'CA_AUTOLOGIN_LENGTH_EXPLAIN' => 'Number of days after which "Remember Me" login keys are removed (1 to 3650, the AWS Cognito Refresh Token validity range).',
	'CA_AUTOLOGIN_LENGTH' => '"Remember Me" login key expiration length (in days)',
	'ACP_COGAUTH_AWS_ERROR' => 'AWS Returned the following Error',
	'APC_COGAUTH_CREATE_USER_POOL' => 'Create Cognito User Pool',
	'APC_COGAUTH_AWS_ACCESS_KEY' => 'IAM Access Key Settings',
	'APC_COGAUTH_AWS_USE_USER_POOL' => 'Use Existing User Pool',
	'APC_COGAUTH_AWS_CREATE_USER_POOL' => 'Create New User Pool',
	'APC_COGAUTH_AWS_APP_CLIENT' => 'Configure App Client',
));
