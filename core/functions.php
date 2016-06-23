<?php

/**
 *
 * @package phpBB Extension - mChat
 * @copyright (c) 2016 dmzx - http://www.dmzx-web.net
 * @copyright (c) 2016 kasimi
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace dmzx\mchat\core;

class functions
{
	/** @var \dmzx\mchat\core\settings */
	protected $settings;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \phpbb\log\log */
	protected $log;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\cache\driver\driver_interface */
	protected $cache;

	/** @var \phpbb\event\dispatcher_interface */
	protected $dispatcher;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/** @var string */
	protected $mchat_table;

	/** @var string */
	protected $mchat_deleted_messages_table;

	/** @var string */
	protected $mchat_sessions_table;

	/**
	 * Value of the phpbb_mchat.post_id field for login notification
	 * messages if the user session is visible at the time of login
	 */
	const LOGIN_VISIBLE	= 1;

	/**
	 * Value of the phpbb_mchat.post_id field for login notification
	 * messages if the user session is hidden at the time of login
	 */
	const LOGIN_HIDDEN	= 2;

	/**
	* Constructor
	*
	* @param \dmzx\mchat\core\settings				$settings
	* @param \phpbb\user							$user
	* @param \phpbb\auth\auth						$auth
	* @param \phpbb\log\log_interface				$log
	* @param \phpbb\db\driver\driver_interface		$db
	* @param \phpbb\cache\driver\driver_interface	$cache
	 * @param \phpbb\event\dispatcher_interface 	$dispatcher
	* @param string									$root_path
	* @param string									$php_ext
	* @param string									$mchat_table
	* @param string									$mchat_deleted_messages_table
	* @param string									$mchat_sessions_table
	*/
	function __construct(\dmzx\mchat\core\settings $settings, \phpbb\user $user, \phpbb\auth\auth $auth, \phpbb\log\log_interface $log, \phpbb\db\driver\driver_interface $db, \phpbb\cache\driver\driver_interface $cache, \phpbb\event\dispatcher_interface $dispatcher, $root_path, $php_ext, $mchat_table, $mchat_deleted_messages_table, $mchat_sessions_table)
	{
		$this->settings						= $settings;
		$this->user							= $user;
		$this->auth							= $auth;
		$this->log							= $log;
		$this->db							= $db;
		$this->cache						= $cache;
		$this->dispatcher					= $dispatcher;
		$this->root_path					= $root_path;
		$this->php_ext						= $php_ext;
		$this->mchat_table					= $mchat_table;
		$this->mchat_deleted_messages_table	= $mchat_deleted_messages_table;
		$this->mchat_sessions_table			= $mchat_sessions_table;
	}

	/**
	 * Converts a number of seconds to a string in the format 'x hours y minutes z seconds'
	 *
	 * @param int $time
	 * @return string
	 */
	protected function mchat_format_seconds($time)
	{
		$times = array();

		$hours = floor($time / 3600);
		if ($hours)
		{
			$time -= $hours * 3600;
			$times[] = $this->user->lang('MCHAT_HOURS', $hours);
		}

		$minutes = floor($time / 60);
		if ($minutes)
		{
			$time -= $minutes * 60;
			$times[] = $this->user->lang('MCHAT_MINUTES', $minutes);
		}

		$seconds = ceil($time);
		if ($seconds)
		{
			$times[] = $this->user->lang('MCHAT_SECONDS', $seconds);
		}

		return $this->user->lang('MCHAT_ONLINE_EXPLAIN', implode('&nbsp;', $times));
	}

	/**
	 * Returns the total session time in seconds
	 *
	 * @return int
	 */
	protected function mchat_session_time()
	{
		$mchat_timeout = $this->settings->cfg('mchat_timeout');
		if ($mchat_timeout)
		{
			return $mchat_timeout;
		}

		$load_online_time = $this->settings->cfg('load_online_time');
		if ($load_online_time)
		{
			return $load_online_time * 60;
		}

		return $this->settings->cfg('session_length');
	}

	/**
	 * Returns data about users who are currently chatting
	 *
	 * @return array
	 */
	public function mchat_active_users()
	{
		$check_time = time() - $this->mchat_session_time();

		$sql = 'SELECT m.user_id, u.username, u.user_type, u.user_allow_viewonline, u.user_colour
			FROM ' . $this->mchat_sessions_table . ' m
			LEFT JOIN ' . USERS_TABLE . ' u ON m.user_id = u.user_id
			WHERE m.user_lastupdate > ' . (int) $check_time . '
			ORDER BY u.username ASC';
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		$mchat_users = array();
		$can_view_hidden = $this->auth->acl_get('u_viewonline');

		foreach ($rows as $row)
		{
			if (!$row['user_allow_viewonline'])
			{
				if (!$can_view_hidden && $row['user_id'] !== $this->user->data['user_id'])
				{
					continue;
				}

				$row['username'] = '<em>' . $row['username'] . '</em>';
			}

			$mchat_users[] = get_username_string('full', $row['user_id'], $row['username'], $row['user_colour'], $this->user->lang('GUEST'));
		}

		return array(
			'online_userlist'	=> implode($this->user->lang('COMMA_SEPARATOR'), $mchat_users),
			'users_count_title'	=> $this->user->lang('MCHAT_TITLE_COUNT', count($mchat_users)),
			'users_total'		=> $this->user->lang('MCHAT_ONLINE_USERS_TOTAL', count($mchat_users)),
			'refresh_message'	=> $this->mchat_format_seconds($this->mchat_session_time()),
		);
	}

	/**
	 * Inserts the current user into the mchat_sessions table
	 *
	 * @return bool Returns true if a new session was created, otherwise false
	 */
	public function mchat_add_user_session()
	{
		// Remove expired sessions from the database
		$check_time = time() - $this->mchat_session_time();
		$sql = 'DELETE FROM ' . $this->mchat_sessions_table . '
			WHERE user_lastupdate < ' . (int) $check_time;
		$this->db->sql_query($sql);

		$user_id = $this->user->data['user_id'];

		if (!$this->user->data['is_registered'] || $this->user->data['is_bot'])
		{
			return false;
		}

		$sql = 'SELECT user_id
			FROM ' . $this->mchat_sessions_table . '
			WHERE user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		$is_new_session = false;

		if ($row)
		{
			$sql = 'UPDATE ' . $this->mchat_sessions_table . '
				SET user_lastupdate = ' . time() . '
				WHERE user_id = ' . (int) $user_id;
		}
		else
		{
			$is_new_session = true;

			$sql = 'INSERT INTO ' . $this->mchat_sessions_table . ' ' . $this->db->sql_build_array('INSERT', array(
				'user_id'			=> (int) $user_id,
				'user_ip'			=> $this->user->data['user_ip'],
				'user_lastupdate'	=> time(),
			));
		}

		$this->db->sql_query($sql);

		return $is_new_session;
	}

	/**
	 * Prune messages
	 *
	 * @return array
	 */
	public function mchat_prune()
	{
		$sql_aray = array(
			'SELECT'	=> 'message_id',
			'FROM'		=> array($this->mchat_table => 'm'),
		);

		$prune_num = $this->settings->cfg('mchat_prune_num');

		if (ctype_digit($prune_num))
		{
			// Retain fixed number of messages
			$offset = $prune_num;
			$sql_aray['ORDER_BY'] = 'message_id DESC';
		}
		else
		{
			// Retain messages of a time period
			$time_period = strtotime($prune_num, 0);

			if ($time_period === false)
			{
				$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_MCHAT_TABLE_PRUNE_FAIL', false, array($this->user->data['username']));
				return false;
			}

			$offset = 0;
			$sql_aray['WHERE'] = 'message_time < ' . (int) (time() - $time_period);
		}

		$sql = $this->db->sql_build_query('SELECT', $sql_aray);
		$result = $this->db->sql_query_limit($sql, 0, $offset);
		$rows = $this->db->sql_fetchrowset();
		$this->db->sql_freeresult($result);

		$prune_ids = array();

		foreach ($rows as $row)
		{
			$prune_ids[] = (int) $row['message_id'];
		}

		if ($prune_ids)
		{
			$this->db->sql_query('DELETE FROM ' . $this->mchat_table . ' WHERE ' . $this->db->sql_in_set('message_id', $prune_ids));
			$this->db->sql_multi_insert($this->mchat_deleted_messages_table, $rows);
			$this->cache->destroy('sql', $this->mchat_deleted_messages_table);
		}

		$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_MCHAT_TABLE_PRUNED', false, array($this->user->data['username'], count($prune_ids)));

		return $prune_ids;
	}

	/**
	 * Returns the total number of messages
	 *
	 * @return string
	 */
	public function mchat_total_message_count()
	{
		return $this->db->get_row_count($this->mchat_table);
	}

	/**
	 * Fetch messages from the database
	 *
	 * @param string $sql_where
	 * @param int $total
	 * @param int $offset
	 * @return array
	 */
	public function mchat_get_messages($sql_where, $total = 0, $offset = 0)
	{
		$sql_where_ary = empty($sql_where) ? array() : array($sql_where);

		if ($this->settings->cfg('mchat_posts'))
		{
			// If the current user doesn't have permission to see hidden users, exclude their login posts
			if (!$this->auth->acl_get('u_viewonline'))
			{
				$sql_where_ary[] = implode(' OR ', array(
					'm.post_id <> ' . self::LOGIN_HIDDEN,			// Exclude all notifications that were created by hidden users ...
					'm.user_id = ' . $this->user->data['user_id'],	// ... but include all login notifications of the current user
					'm.forum_id <> 0',								// ... and include all post notifications
				));
			}
		}
		else
		{
			// Exclude all post notifications
			$sql_where_ary[] = 'm.post_id = 0';
		}

		$sql_array = array(
			'SELECT'	=> 'm.*, u.username, u.user_colour, u.user_avatar, u.user_avatar_type, u.user_avatar_width, u.user_avatar_height, u.user_allow_pm, p.post_visibility',
			'FROM'		=> array($this->mchat_table	=> 'm'),
			'LEFT_JOIN'	=> array(
				array(
					'FROM'	=> array(USERS_TABLE => 'u'),
					'ON'	=> 'm.user_id = u.user_id',
				),
				array(
					'FROM'	=> array(POSTS_TABLE => 'p'),
					'ON'	=> 'm.post_id = p.post_id',
				)
			),
			'WHERE'		=> $sql_where_ary ? $this->db->sql_escape('(' . implode(') AND (', $sql_where_ary) . ')') : '',
			'ORDER_BY'	=> 'm.message_id DESC',
		);

		/**
		 * @event dmzx.mchat.get_messages_modify_sql
		 * @var	array	sql_array	Array containing the SQL query data
		 * @var int		total		SQL limit
		 * @var int		offset		SQL offset
		 * @since 2.0.0-RC6
		 */
		$vars = array(
			'sql_array',
			'total',
			'offset',
		);
		extract($this->dispatcher->trigger_event('dmzx.mchat.get_messages_modify_sql', compact($vars)));

		$sql = $this->db->sql_build_query('SELECT', $sql_array);
		$result = $this->db->sql_query_limit($sql, $total, $offset);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		// Set deleted users to ANONYMOUS
		foreach ($rows as $i => $row)
		{
			if (!isset($row['username']))
			{
				$rows[$i]['user_id'] = ANONYMOUS;
			}
		}

		return $rows;
	}

	/**
	 * Generates the user legend markup
	 *
	 * @return array Array of HTML markup for each group
	 */
	public function mchat_legend()
	{
		// Grab group details for legend display for who is online on the custom page
		$order_legend = $this->settings->cfg('legend_sort_groupname') ? 'group_name' : 'group_legend';

		$sql_array = array(
			'SELECT'	=> 'g.group_id, g.group_name, g.group_colour, g.group_type',
			'FROM'		=> array(GROUPS_TABLE => 'g'),
			'WHERE'		=> 'group_legend <> 0',
			'ORDER_BY'	=> 'g.' . $order_legend . ' ASC',
		);

		if ($this->auth->acl_gets('a_group', 'a_groupadd', 'a_groupdel'))
		{
			$sql_array['LEFT_JOIN'] = array(
				array(
					'FROM'	=> array(USER_GROUP_TABLE => 'ug'),
					'ON'	=> 'g.group_id = ug.group_id AND ug.user_id = ' . (int) $this->user->data['user_id'] . ' AND ug.user_pending = 0',
				),
			);

			$sql_array['WHERE'] .= ' AND (g.group_type <> ' . GROUP_HIDDEN . ' OR ug.user_id = ' . (int) $this->user->data['user_id'] . ')';
		}

		$sql = $this->db->sql_build_query('SELECT', $sql_array);
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		$legend = array();
		foreach ($rows as $row)
		{
			$colour_text = $row['group_colour'] ? ' style="color:#' . $row['group_colour'] . '"' : '';
			$group_name = $row['group_type'] == GROUP_SPECIAL ? $this->user->lang('G_' . $row['group_name']) : $row['group_name'];
			if ($row['group_name'] == 'BOTS' || $this->user->data['user_id'] != ANONYMOUS && !$this->auth->acl_get('u_viewprofile'))
			{
				$legend[] = '<span' . $colour_text . '>' . $group_name . '</span>';
			}
			else
			{
				$legend[] = '<a' . $colour_text . ' href="' . append_sid("{$this->root_path}memberlist.{$this->php_ext}", 'mode=group&amp;g='. $row['group_id']) . '">' . $group_name . '</a>';
			}
		}

		return $legend;
	}

	/**
	 * Returns a list of all foes of the current user
	 *
	 * @return array Array of user IDs
	 */
	public function mchat_foes()
	{
		$sql = 'SELECT zebra_id
			FROM ' . ZEBRA_TABLE . '
			WHERE foe = 1
				AND user_id = ' . (int) $this->user->data['user_id'];
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		$foes = array();

		foreach ($rows as $row)
		{
			$foes[] = $row['zebra_id'];
		}

		return $foes;
	}

	/**
	 * Adds forbidden BBCodes to the passed SQL where statement
	 *
	 * @param string $sql_where
	 * @return string
	 */
	public function mchat_sql_append_forbidden_bbcodes($sql_where)
	{
		$disallowed_bbcodes = explode('|', $this->settings->cfg('mchat_bbcode_disallowed'));

		if (!empty($disallowed_bbcodes))
		{
			$sql_where .= ' AND ' . $this->db->sql_in_set('b.bbcode_tag', $disallowed_bbcodes, true);
		}

		return $sql_where;
	}

	/**
	 * Inserts a message with posting information into the database
	 *
	 * @param string $mode One of post|quote|edit|reply|login
	 * @param array $data The post data
	 * @param bool $is_hidden_login
	 */
	public function mchat_insert_posting($mode, $data, $is_hidden_login)
	{
		$mode_config = array(
			'post'	=> 'mchat_posts_topic',
			'quote'	=> 'mchat_posts_quote',
			'edit'	=> 'mchat_posts_edit',
			'reply'	=> 'mchat_posts_reply',
			'login' => 'mchat_posts_login',
		);

		if (empty($mode_config[$mode]) || !$this->settings->cfg($mode_config[$mode]))
		{
			return;
		}

		$message_data = array(
			'MCHAT_NEW_' . strtoupper($mode),
		);

		if ($mode === 'login')
		{
			$data = array(
				'forum_id'	=> 0,
				'post_id'	=> $is_hidden_login ? self::LOGIN_HIDDEN : self::LOGIN_VISIBLE,
			);
		}
		else
		{
			$message_data[] = $data['post_subject'];
			$message_data[] = $data['forum_name'];
		}

		$sql_ary = array(
			'forum_id'			=> $data['forum_id'],
			'post_id'			=> $data['post_id'],
			'user_id'			=> $this->user->data['user_id'],
			'user_ip'			=> $this->user->data['session_ip'],
			'message'			=> json_encode($message_data),
			'message_time'		=> time(),
		);

		$sql = 'INSERT INTO ' .	$this->mchat_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary);
		$this->db->sql_query($sql);
	}

	/**
	 * Checks if the current user is flooding the chat
	 *
	 * @return bool
	 */
	public function mchat_is_user_flooding()
	{
		if (!$this->settings->cfg('mchat_flood_time') || $this->auth->acl_get('u_mchat_flood_ignore'))
		{
			return false;
		}

		$sql = 'SELECT message_time
			FROM ' . $this->mchat_table . '
			WHERE user_id = ' . (int) $this->user->data['user_id'] . '
			ORDER BY message_time DESC';
		$result = $this->db->sql_query_limit($sql, 1);
		$message_time = (int) $this->db->sql_fetchfield('message_time');
		$this->db->sql_freeresult($result);

		return $message_time && time() - $message_time < $this->settings->cfg('mchat_flood_time');
	}

	/**
	 * Returns user ID & name of the specified message
	 *
	 * @param $message_id
	 * @return array
	 */
	public function mchat_author_for_message($message_id)
	{
		$sql = 'SELECT u.user_id, u.username, m.message_time, m.forum_id, m.post_id
			FROM ' . $this->mchat_table . ' m
			LEFT JOIN ' . USERS_TABLE . ' u ON m.user_id = u.user_id
			WHERE m.message_id = ' . (int) $message_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row;
	}

	/**
	 * Returns an array of message IDs that have been deleted from the message table
	 *
	 * @param $start_id
	 * @return array
	 */
	public function mchat_deleted_ids($start_id)
	{
		$sql = 'SELECT message_id
			FROM ' . $this->mchat_deleted_messages_table . '
			WHERE message_id >= ' . (int) $start_id . '
			ORDER BY message_id DESC';
		$result = $this->db->sql_query($sql, 3600);
		$rows = $this->db->sql_fetchrowset();
		$this->db->sql_freeresult($result);

		$missing_ids = array();
		foreach ($rows as $row)
		{
			$missing_ids[] = (int) $row['message_id'];
		}

		return $missing_ids;
	}

	/**
	 * Performs AJAX actions
	 *
	 * @param string $action One of add|edit|del
	 * @param array $sql_ary
	 * @param int $message_id
	 * @return bool
	 */
	public function mchat_action($action, $sql_ary = null, $message_id = 0)
	{
		$is_new_session = false;

		/**
		 * @event dmzx.mchat.action_before
		 * @var	string	action		The action that is being performed, one of add|edit|del
		 * @var bool	sql_ary		Array containing SQL data, or null if a message is deleted
		 * @var int		message_id	The ID of the message that is being edited or deleted, or 0 if a message is added
		 * @since 2.0.0-RC6
		 */
		$vars = array(
			'action',
			'sql_ary',
			'message_id',
		);
		extract($this->dispatcher->trigger_event('dmzx.mchat.action_before', compact($vars)));

		switch ($action)
		{
			// User adds a message
			case 'add':
				$this->user->update_session_infos();
				$is_new_session = $this->mchat_add_user_session();
				$this->db->sql_query('INSERT INTO ' . $this->mchat_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
				break;

			// User edits a message
			case 'edit':
				$this->user->update_session_infos();
				$is_new_session = $this->mchat_add_user_session();
				$this->db->sql_query('UPDATE ' . $this->mchat_table . ' SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . ' WHERE message_id = ' . (int) $message_id);
				$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_EDITED_MCHAT', false, array($this->user->data['username']));
				break;

			// User deletes a message
			case 'del':
				$this->user->update_session_infos();
				$is_new_session = $this->mchat_add_user_session();
				$this->db->sql_query('DELETE FROM ' . $this->mchat_table . ' WHERE message_id = ' . (int) $message_id);
				$this->db->sql_query('INSERT INTO ' . $this->mchat_deleted_messages_table . ' ' . $this->db->sql_build_array('INSERT', array('message_id' => (int) $message_id)));
				$this->cache->destroy('sql', $this->mchat_deleted_messages_table);
				$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_DELETED_MCHAT', false, array($this->user->data['username']));
				break;
		}

		return $is_new_session;
	}
}
