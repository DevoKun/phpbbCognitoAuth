<?php
/**
 *
 * AWS Cognito Authentication. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2018, Mark Gawler
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace mrfg\cogauth\event;

/**
 * @ignore
 */

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * AWS Cognito Authentication Event listener.
 */
class main_listener implements EventSubscriberInterface
{
	static public function getSubscribedEvents()
	{
		return array(
			'core.session_kill_after'		=> 'session_kill_after',
			'core.user_setup'				=> 'load_language_on_setup',
			'core.ucp_profile_reg_details_validate' => 'ucp_profile_update',
			'core.session_create_after' 	=> 'session_create_after',
			'core.acp_users_overview_modify_data' => 'acp_profile_update',
			'core.delete_user_after' 		=> 'delete_users',
			'core.user_active_flip_after' 	=> 'user_active_flip',
			//'core.session_gc_after' 		=> 'session_gc_after',
			//'core.user_setup_after'			=> 'user_setup_after',
		);
	}

	/* @var \phpbb\user */
	protected $user;

	/* @var \mrfg\cogauth\cognito\cognito */
	protected $client;

	/* @var \phpbb\event\dispatcher_interface */
	protected $dispatcher;

	/**
	 * Constructor
	 *
	 * @param \phpbb\user               $user       User object
	 * @param \mrfg\cogauth\cognito\cognito $client
	 * @param \phpbb\event\dispatcher_interface	$dispatcher	Event dispatcher
	 */
	public function __construct(
		\phpbb\user $user,
		\mrfg\cogauth\cognito\cognito $client,
		\phpbb\event\dispatcher_interface $dispatcher)
	{
		$this->user = $user;
		$this->client = $client;
		$this->dispatcher = $dispatcher;
	}


	/**
	 * Load common language files during user setup
	 *
	 * @param \phpbb\event\data	$event	Event object
	 */
	public function load_language_on_setup($event)
	{
		$lang_set_ext = $event['lang_set_ext'];
		$lang_set_ext[] = array(
			'ext_name' => 'mrfg/cogauth',
			'lang_set' => 'common',
		);
		$event['lang_set_ext'] = $lang_set_ext;
	}


	/**
	 * @param \phpbb\event\data	$event	Event object
	 */
	public function session_gc_after(/** @noinspection PhpUnusedParameterInspection */ $event)
	{
		$this->client->delete_expired_sessions();
	}

	/**
	 * @param \phpbb\event\data	$event	Event object
	 */
	public function session_create_after($event)
	{
		$data = $event['session_data'];
		if ($data['session_user_id'] !== 1)  // user_id of 1 = Guest
        {
			// Now we have the SID we can store it in the cogauth_session table..
            $this->client->store_sid($data['session_id']);

			/** @noinspection PhpUnusedLocalVariableInspection */
			$session_token = $this->client->get_session_token();

			/**
			 * Cogauth session after create event
			 *
			 * @event mrfg.cogauth.session_create_after
			 * @var  string  session_token
			 * @since 1.1
			 */
			$vars = array('session_token',);
			extract($this->dispatcher->trigger_event('mrfg.cogauth.session_create_after', compact($vars)));
		}
    }

	/**
	 * @param \phpbb\event\data	$event	Event object
	 */
	public function session_kill_after($event)
	{
		$session = $event['session_id'];
		/** @noinspection PhpUnusedLocalVariableInspection */
		$session_token = $this->client->get_session_token();

		$this->client->phpbb_session_killed($session);

		/**
		 * Cogauth session kill after event
		 *
		 * @event mrfg.cogauth.session_kill_after
		 * @var  string  session_token
		 * @since 1.1
		 */
		$vars = array('session_token',);
		extract($this->dispatcher->trigger_event('mrfg.cogauth.session_kill_after', compact($vars)));
	}

	/**
   	 * @param \phpbb\event\data	$event	Event object
	 */
	public function ucp_profile_update($event)
	{

		if ($event['submit'] &&  !sizeof($event['error']))
		{
			$data = $event['data'];

			$access_token = $this->client->get_access_token();
			if (isset($access_token))
			{
				$user_id = $this->user->data['user_id'];
				if (!empty($data['email']))
				{
					if (! $this->client->update_user_email($user_id, $data['email'], $access_token))
					{
						$event['error'] = array('COGAUTH_EMAIL_CHANGE_ERROR');
					}
				}
				if (!empty($data['new_password']))
				{
					error_log('Password Change: ' . $event['data']['new_password']);
					if (! $this->client->change_password($user_id, $access_token, $data['cur_password'], $data['new_password']))
					{
						$event['error'] = array('COGAUTH_PASSWORD_ERROR');
					}
				}
			}
			else
			{
				//TODO this is not an error if the user has not been migrated, we should migrate the user and set the password.
				//error_log('No Access token found');
				$event['error'] = array('COGAUTH_PASSWORD_ERROR');
			}
		}
	}

	/**
	 * @param \phpbb\event\data	$event	Event object
	 */
	public function acp_profile_update($event)
	{
		$data = $event['data'];
		$user_row = $event['user_row'];
		$user_id = $event['user_row']['user_id'];

		if (!empty($data['email']) && $data['email'] != $user_row['user_email'])
		{
			$this->client->admin_update_email($user_id,$data['email']);
		}

		if (!empty($data['new_password']))
		{
			$this->client->admin_change_password($user_id,$data['new_password']);
		}

		$username_clean = utf8_clean_string($data['username']);
		if (!empty($username_clean) && $username_clean != $user_row['username_clean'])
		{
			$this->client->admin_update_username($user_id,$data['username']);
		}
	}

	/**
	 * @param \phpbb\event\data	$event	Event object
	 */
	public function delete_users($event)
	{
		foreach ($event['user_ids'] as $user_id)
		{
			$this->client->admin_delete_user($user_id);
		}
	}

	public function user_active_flip($event)
	{
		foreach ($event['user_id_ary'] as $user_id)
		{
			$activated = $event['activated'];
			$deactivated = $event['deactivated'];

			switch ($event['mode'])
			{
				case 'flip':
					if ($activated && $deactivated)
					{
						//TODO error handling
						error_log('Ambiguous activation/deactivation');
					}
					elseif ($activated)
					{
						$this->client->admin_enable_user($user_id);
					}
					else
					{
						$this->client->admin_disable_user($user_id);
					}
				break;
				case 'activate':
					$this->client->admin_enable_user($user_id);
				break;
				case 'deactivate':
					$this->client->admin_disable_user($user_id);
				break;
			}
		}
	}
	/*
	public function user_setup_after()
	{
		$last_active =  $this->client->get_last_active();
		$diff = time() - $last_active;
		if ($diff > 30)
		{
			error_log('update Last Active: ' );
			$this->client->update_last_active();
		}
	}
	*/
}

