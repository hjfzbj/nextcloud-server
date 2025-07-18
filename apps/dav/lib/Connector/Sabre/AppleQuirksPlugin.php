<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\DAV\Connector\Sabre;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * A plugin which tries to work-around peculiarities of the MacOS DAV client
 * apps. The following problems are addressed:
 *
 * - OSX calendar client sends REPORT requests to a random principal
 *   collection but expects to find all principals (forgot to set
 *   {DAV:}principal-property-search flag?)
 */
class AppleQuirksPlugin extends ServerPlugin {

	/*
	private const OSX_CALENDAR_AGENT = 'CalendarAgent';
	private const OSX_DATAACCESSD_AGENT = 'dataaccessd';
	private const OSX_ACCOUNTSD_AGENT = 'accountsd';
	private const OSX_CONTACTS_AGENT = 'AddressBookCore';
	*/

	private const OSX_AGENT_PREFIX = 'macOS';

	/** @var bool */
	private $isMacOSDavAgent = false;

	/**
	 * Sets up the plugin.
	 *
	 * This method is automatically called by the server class.
	 *
	 * @return void
	 */
	public function initialize(Server $server) {
		$server->on('beforeMethod:REPORT', [$this, 'beforeReport'], 0);
		$server->on('report', [$this, 'report'], 0);
	}

	/**
	 * Triggered before any method is handled.
	 *
	 * @return void
	 */
	public function beforeReport(RequestInterface $request, ResponseInterface $response) {
		$userAgent = $request->getRawServerValue('HTTP_USER_AGENT') ?? 'unknown';
		$this->isMacOSDavAgent = $this->isMacOSUserAgent($userAgent);
	}

	/**
	 * This method handles HTTP REPORT requests.
	 *
	 * @param string $reportName
	 * @param mixed $report
	 * @param mixed $path
	 *
	 * @return bool
	 */
	public function report($reportName, $report, $path) {
		if ($reportName == '{DAV:}principal-property-search' && $this->isMacOSDavAgent) {
			/** @var \Sabre\DAVACL\Xml\Request\PrincipalPropertySearchReport $report */
			$report->applyToPrincipalCollectionSet = true;
		}
		return true;
	}

	/**
	 * Check whether the given $userAgent string pretends to originate from OSX.
	 *
	 * @param string $userAgent
	 *
	 * @return bool
	 */
	protected function isMacOSUserAgent(string $userAgent):bool {
		return str_starts_with(self::OSX_AGENT_PREFIX, $userAgent);
	}

	/**
	 * Decode the given OSX DAV agent string.
	 *
	 * @param string $agent
	 *
	 * @return null|array
	 */
	protected function decodeMacOSAgentString(string $userAgent):?array {
		// OSX agent string is like: macOS/13.2.1 (22D68) dataaccessd/1.0
		if (preg_match('|^' . self::OSX_AGENT_PREFIX . '/([0-9]+)\\.([0-9]+)\\.([0-9]+)\s+\((\w+)\)\s+([^/]+)/([0-9]+)(?:\\.([0-9]+))?(?:\\.([0-9]+))?$|i', $userAgent, $matches)) {
			return [
				'macOSVersion' => [
					'major' => $matches[1],
					'minor' => $matches[2],
					'patch' => $matches[3],
				],
				'macOSAgent' => $matches[5],
				'macOSAgentVersion' => [
					'major' => $matches[6],
					'minor' => $matches[7] ?? null,
					'patch' => $matches[8] ?? null,
				],
			];
		}
		return null;
	}
}
