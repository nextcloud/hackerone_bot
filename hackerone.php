<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Joas Schilling <coding@schilljs.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const USER_AGENT = 'nextcloud-talk-hackerone-adapter/1.0';

function sanitizeStringForTalkMessage(string $text): string {
	// Delimiter must be longer than any backtick run inside, spaces keep edge backticks from merging with it
	preg_match_all('/`+/', $text, $matches);
	$delimiter = str_repeat('`', max([0, ...array_map(strlen(...), $matches[0])]) + 1);
	return $delimiter . ' ' . $text . ' ' . $delimiter;
}

function sendChatMessage(array $config, string $referenceId, string $message): void {
	$body = [
		'message' => $message,
		'referenceId' => $referenceId,
	];

	$jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

	$random = bin2hex(random_bytes(32));
	$hash = hash_hmac('sha256', $random . $message, $config['nextcloud-secret']);

	$ch = curl_init(rtrim($config['server'], '/') . '/ocs/v2.php/apps/spreed/api/v1/bot/' . $config['conversation'] . '/message');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
	curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);
	curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
	curl_setopt($ch, CURLOPT_HTTPHEADER, [
		'OCS-APIRequest: true',
		'Content-Type: application/json',
		'X-Nextcloud-Talk-Bot-Random: ' . $random,
		'X-Nextcloud-Talk-Bot-Signature: ' . $hash,
	]);
	curl_exec($ch);
	curl_close($ch);
}

function fetchTriageCalendar(string $url, \DateTimeImmutable $day): ?string {
	if (str_contains($url, '?')) {
		return null;
	}

	$ch = curl_init($url . '?' . http_build_query([
		'export' => '',
		'expand' => 1,
		'start' => $day->modify('-1 day')->getTimestamp(),
		'end' => $day->modify('+2 day')->getTimestamp(),
	]));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, 5);
	curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);
	$ics = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);
	return $ics === false || $status !== 200 ? null : $ics;
}

function getTriagePeople(string $ics, \DateTimeImmutable $day): array {
	// Unfold continuation lines (RFC 5545 3.1)
	$ics = preg_replace('/\r?\n[ \t]/', '', $ics);
	$date = $day->format('Ymd');
	$people = [];
	preg_match_all('/^BEGIN:VEVENT\r?$(.*?)^END:VEVENT\r?$/ms', $ics, $events);
	foreach ($events[1] as $event) {
		preg_match('/^DTSTART[^:\r\n]*:(\d{8})/m', $event, $start);
		preg_match('/^DTEND[^:\r\n]*:(\d{8})/m', $event, $end);
		preg_match('/^SUMMARY[^:\r\n]*:(.*?)\r?$/m', $event, $summary);
		preg_match('/^CLASS[^:\r\n]*:(\S+)/m', $event, $class);
		$person = trim(stripcslashes($summary[1] ?? ''));
		// Confidential events are exported with a translated "Busy" summary
		if (!$start || !preg_match('/^[a-z]+$/', $person) || ($class[1] ?? 'PUBLIC') !== 'PUBLIC') {
			continue;
		}
		// DTEND is exclusive
		$endDate = $end[1] ?? $start[1];
		if ($start[1] <= $date && ($date < $endDate || $date === $start[1])) {
			$people[] = $person;
		}
	}
	return array_unique($people);
}

$signature = $_SERVER['HTTP_X_NEXTCLOUD_TALK_SIGNATURE'] ?? '';
$random = $_SERVER['HTTP_X_NEXTCLOUD_TALK_RANDOM'] ?? '';
$server = $_SERVER['HTTP_X_NEXTCLOUD_TALK_BACKEND'] ?? '';
if ($signature && $random && $server) {
	return;
}


$configData = file_get_contents('../hackerone.json');

if ($configData === false) {
	return;
}

$config = json_decode($configData, true);

if ($config === null) {
	return;
}

if (isset($_REQUEST['monitoring'])) {
	echo '{"status":"OK"}';
	return;
}

$delivery = $_SERVER['HTTP_X_H1_DELIVERY'] ?? '';
$event = $_SERVER['HTTP_X_H1_EVENT'] ?? '';
$signature = $_SERVER['HTTP_X_H1_SIGNATURE'] ?? '';

if ($delivery === '' || $event === '' || $signature === '') {
	sendChatMessage($config, $delivery, '[ERROR] Hackerone Webhook: Missing header data');
	return;
}

[, $digest] = explode('=', $signature, 2);
if (!$digest) {
	sendChatMessage($config, $delivery, '[ERROR] Hackerone Webhook: Invalid signature');
	return;
}


$body = file_get_contents('php://input');

$generatedDigest = hash_hmac('sha256', $body, $config['hackerone-secret']);

if ($generatedDigest !== $digest) {
	sendChatMessage($config, $delivery, '[ERROR] Hackerone Webhook: Wrong signature');
	return;
}

$data = json_decode($body, true);

if ($data === null) {
	sendChatMessage($config, $delivery, '[ERROR] Hackerone Webhook: Invalid request body');
	return;
}

if (empty($data['data']['report']['id'])) {
	sendChatMessage($config, $delivery, '[ERROR] Hackerone Webhook: Invalid request body - Missing ID');
	return;
}

if ($event !== 'report_new' && $event !== 'report_created') {
	return;
}

$reporterUsername = $data['data']['report']['relationships']['reporter']['data']['attributes']['username'] ?? '';
if (in_array($reporterUsername, $config['skip-reporters'] ?? [], true)) {
	return;
}

$title = '';
if (!empty($data['data']['report']['attributes']['title'])) {
	$title = sanitizeStringForTalkMessage($data['data']['report']['attributes']['title']) . "\n\n";
}

$reporterName = '';
if (!empty($data['data']['report']['relationships']['reporter']['data']['attributes']['name'])) {
	$reporterName = sanitizeStringForTalkMessage($data['data']['report']['relationships']['reporter']['data']['attributes']['name']);
}

if ($reporterUsername !== '') {
	if ($reporterName !== '') {
		$reporterName = sanitizeStringForTalkMessage($reporterUsername) . ' (' . $reporterName . ')';
	} else {
		$reporterName = sanitizeStringForTalkMessage($reporterUsername);
	}
}

if ($reporterName !== '') {
	$reporterName = ' by ' . $reporterName;
}

$triage = '';
if (!empty($config['triage-calendar'])) {
	$today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
	$ics = fetchTriageCalendar($config['triage-calendar'], $today);
	$people = $ics === null ? [] : getTriagePeople($ics, $today);
	$triage = "\n\nTriage: " . match (true) {
		$ics === null => '@all (triage calendar unavailable)',
		$people === [] => '@all',
		default => '@' . implode(' @', $people),
	};
}

if ($event === 'report_new') {
	sendChatMessage(
		$config,
		$delivery,
		'🚨 HackerOne issue reset to new' . $reporterName . "\n\n"
		. $title
		. 'https://hackerone.com/reports/' . $data['data']['report']['id']
		. $triage
	);
} elseif ($event === 'report_created') {
	sendChatMessage(
		$config,
		$delivery,
		'🚨 New HackerOne issue' . $reporterName . "\n\n"
		. $title
		. 'https://hackerone.com/reports/' . $data['data']['report']['id']
		. $triage
	);
}
