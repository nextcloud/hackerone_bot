<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023 Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

function sanitizeStringForTalkMessage(string $text): string {
	return str_replace(['@', 'http://', 'https://'], ['👤', '🔗', '🔗🔒'], $text);
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
	curl_setopt($ch, CURLOPT_USERAGENT, 'nextcloud-talk-hackerone-adapter/1.0');
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

$title = '';
if (!empty($data['data']['report']['attributes']['title'])) {
	$title = sanitizeStringForTalkMessage($data['data']['report']['attributes']['title']) . "\n\n";
}

$reporterName = '';
if (!empty($data['data']['report']['relationships']['reporter']['data']['attributes']['name'])) {
	$reporterName = $data['data']['report']['relationships']['reporter']['data']['attributes']['name'];
}

if (!empty($data['data']['report']['relationships']['reporter']['data']['attributes']['username'])) {
	if ($reporterName !== '') {
		$reporterName = $data['data']['report']['relationships']['reporter']['data']['attributes']['username'] . ' (' . $reporterName . ')';
	} else {
		$reporterName = $data['data']['report']['relationships']['reporter']['data']['attributes']['username'];
	}
}

if ($reporterName !== '') {
	$reporterName = sanitizeStringForTalkMessage(' by ' . $reporterName);
}

if ($event === 'report_new') {
	sendChatMessage(
		$config,
		$delivery,
		'🚨 HackerOne issue reset to new' . $reporterName . "\n\n"
		. $title
		. 'https://hackerone.com/reports/' . $data['data']['report']['id']);
} elseif ($event === 'report_created') {
	sendChatMessage(
		$config,
		$delivery,
		'🚨 New HackerOne issue' . $reporterName . "\n\n"
		. $title
		. 'https://hackerone.com/reports/' . $data['data']['report']['id']);
}
