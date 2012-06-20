<?php
///////////////////////////////////////////////////////////////////////////
//                                                                       //
// NOTICE OF COPYRIGHT                                                   //
//                                                                       //
//                      Online Judge for Moodle                          //
//        https://github.com/hit-moodle/moodle-local_onlinejudge         //
//                                                                       //
// Copyright (C) 2009 onwards  Sun Zhigang  http://sunner.cn             //
//                                                                       //
// This program is free software; you can redistribute it and/or modify  //
// it under the terms of the GNU General Public License as published by  //
// the Free Software Foundation; either version 3 of the License, or     //
// (at your option) any later version.                                   //
//                                                                       //
// This program is distributed in the hope that it will be useful,       //
// but WITHOUT ANY WARRANTY; without even the implied warranty of        //
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the         //
// GNU General Public License for more details:                          //
//                                                                       //
//          http://www.gnu.org/copyleft/gpl.html                         //
//                                                                       //
///////////////////////////////////////////////////////////////////////////

/**
 * java judge engine
 *
 * @package   local_onlinejudge
 * @copyright 2011 Sun Zhigang (http://sunner.cn)
 * @author    Sun Zhigang
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__FILE__)."/../../../../config.php");
require_once($CFG->dirroot."/local/onlinejudge/judgelib.php");

///define('SANDBOX_SAND', escapeshellcmd($CFG->dirroot.'/local/onlinejudge/judge/java/libphp'));

class judge_java extends judge_base {
	protected static $supported_languages = array(
			1 => 'C++ (gcc-4.3.4, WSAnalisador)'
	);

	static function get_languages() {
		$langs = array();
		if (!self::is_available()) {
			return $langs;
		}
		foreach (self::$supported_languages as $langid => $name) {
			$langs[$langid.'_wsanalisador'] = $name;
		}
		return $langs;
	}

	/**
	 * Judge the current task
	 *
	 * @return updated task
	 */
	function judge() {

		$task = &$this->task;

		// create client.
		$client = new SoapClient("http://192.168.1.4:8080/wsAnalisador/services/Analisador?wsdl");

		$user = $task->var1;
		$pass = $task->var2;
		$language = $this->language;
		$input = $task->input;

		// Get source code
		$fs = get_file_storage();
		$files = $fs->get_area_files(get_context_instance(CONTEXT_SYSTEM)->id, 'local_onlinejudge', 'tasks', $task->id, 'sortorder, timemodified', false);
		$source = '';
		foreach ($files as $file) {
			$source = $file->get_content();
			break;
		}

		$status_ideone = array(
				0   => ONLINEJUDGE_STATUS_PENDING,
				11  => ONLINEJUDGE_STATUS_COMPILATION_ERROR,
				12  => ONLINEJUDGE_STATUS_RUNTIME_ERROR,
				13  => ONLINEJUDGE_STATUS_TIME_LIMIT_EXCEED,
				15  => ONLINEJUDGE_STATUS_COMPILATION_OK,
				17  => ONLINEJUDGE_STATUS_MEMORY_LIMIT_EXCEED,
				19  => ONLINEJUDGE_STATUS_RESTRICTED_FUNCTIONS,
				20  => ONLINEJUDGE_STATUS_INTERNAL_ERROR
		);

		// Begin soap
		/**
		* function createSubmission create a paste.
		* @param user is the user name.
		* @param pass is the user's password.
		* @param source is the source code of the paste.
		* @param language is language identifier. these identifiers can be
		*     retrieved by using the getLanguages methods.
		* @param input is the data that will be given to the program on the stdin
		* @param run is the determines whether the source code should be executed.
		* @param private is the determines whether the paste should be private.
		*     Private pastes do not appear on the recent pastes page on ideone.com.
		*     Notice: you can only set submission's visibility to public or private through
		*     the API (you cannot set the user's visibility).
		* @return array(
		*         error => string
		*         link  => string
		*     )
		*/
		$webid = $client->createSubmission($user, $pass, $source, $language, $input, true, true);
		$delay = get_config('local_onlinejudge', 'ideonedelay');
		sleep($delay);  // ideone reject bulk access

		if ($webid['error'] == 'OK') {
			$link = $webid['link'];
		} else {
			throw new onlinejudge_exception('ideoneerror', $webid['error']);
		}

		// Get ideone results
		while (1) {
			$status = $client->getSubmissionStatus($user, $pass, $link);
			sleep($delay);  // ideone reject bulk access. Always add delay between accesses
			if($status['status'] == 0) {
				break;
			}
		}

		$details = $client->getSubmissionDetails($user,$pass,$link,false,false,true,true,true);
		$task->stdout = $details['output'];
		$task->stderr = $details['stderr'];
		$task->compileroutput = $details['cmpinfo'];
		$task->memusage = $details['memory'] * 1024;
		$task->cpuusage = $details['time'];
		$task->infoteacher = get_string('ideoneresultlink', 'local_onlinejudge', $link);
		$task->infostudent = get_string('ideonelogo', 'local_onlinejudge');

		$task->status = $status_ideone[$details['result']];

		if ($task->compileonly) {
			if ($task->status != ONLINEJUDGE_STATUS_COMPILATION_ERROR && $task->status != ONLINEJUDGE_STATUS_INTERNAL_ERROR) {
				$task->status = ONLINEJUDGE_STATUS_COMPILATION_OK;
			}
		} else {
			if ($task->status == ONLINEJUDGE_STATUS_COMPILATION_OK) {
				if ($task->cpuusage > $task->cpulimit) {
					$task->status = ONLINEJUDGE_STATUS_TIME_LIMIT_EXCEED;
				} else if ($task->memusage > $task->memlimit) {
					$task->status = ONLINEJUDGE_STATUS_MEMORY_LIMIT_EXCEED;
				} else {
					$task->status = $this->diff();
				}
			}
		}

		return $task;
	}

	/**
	 * Whether the judge is avaliable
	 *
	 * @return true for yes, false for no
	 */
	static function is_available() {
		return true;
	}
}

