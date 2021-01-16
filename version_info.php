<?php

// *** Source Formatting
// $ php-cs-fixer fix --rules @Symfony,-echo_tag_syntax,-no_alternative_syntax version_info.php
//
// *** Cron
// $ crontab -l
// @hourly	/usr/bin/php /home/fkooman/version_info.php > /var/www/html/fkooman/version_info.html.tmp && mv /var/www/html/fkooman/version_info.html.tmp /var/www/html/fkooman/version_info.html

// set this to the latest version of vpn-user-portal
// @see https://github.com/eduvpn/vpn-user-portal/releases
$latestVersion = '2.3.6';

// discovery URL
$discoUrl = 'https://disco.eduvpn.org/v2/server_list.json';
//$discoUrl = null;

$mailTo = null;
//$mailTo = 'fkooman@tuxed.net';
$mailFrom = 'info@example.org';

// remove notoriously unreliable servers from the error list to prevent mails
// (just) for this server...
$doNotMonitorList = [
//    'https://eduvpn1.eduvpn.de/'
];

$serverList = [];
if (null !== $discoUrl) {
    $serverList = json_decode(getUrl($discoUrl), true)['server_list'];
}

// other servers not part of any discovery file
//$otherServerList = [];
if (file_exists('other_server_list.txt')) {
    $otherBaseUrlList = explode("\n", trim(file_get_contents('other_server_list.txt')));
    foreach ($otherBaseUrlList as $otherBaseUrl) {
        $serverList[] = [
            'base_url' => $otherBaseUrl,
            'server_type' => 'alien',
            'display_name' => parse_url($otherBaseUrl, PHP_URL_HOST),
            'support_contact' => [],
        ];
    }
}

usort($serverList, function ($a, $b) {
    if ($a['server_type'] === $b['server_type']) {
        // same server type, sort by TLD
        $tldA = getTld($a['base_url']);
        $tldB = getTld($b['base_url']);
        if ($tldA === $tldB) {
            // if both TLDs are the same, sort by display name
            return strcmp(getDisplayName($a), getDisplayName($b));
        }

        return strcmp($tldA, $tldB);
    }

    // make sure "Secure Internet" is on top
    if ('secure_internet' === $a['server_type']) {
        return -1;
    }
    if ('secure_internet' === $b['server_type']) {
        return 1;
    }

    // make sure "Alien" is at the bottom
    if ('alien' === $a['server_type']) {
        return 1;
    }
    if ('alien' === $b['server_type']) {
        return -1;
    }

    return strcmp($a['server_type'], $b['server_type']);
});

/**
 * @param string $baseUrl
 *
 * @return string
 */
function getTld($baseUrl)
{
    // remove trailing "/"
    $baseUrl = substr($baseUrl, 0, -1);
    $hostParts = explode('.', $baseUrl);

    return $hostParts[count($hostParts) - 1];
}

/**
 * @param string $u
 *
 * @return string
 */
function getUrl($u)
{
    $maxTry = 3;
    $errorMessage = [];
    for ($i = 0; $i < $maxTry; ++$i) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $u);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        if (false === $responseData = curl_exec($ch)) {
            $errorMsg = curl_error($ch);
            if (!in_array($errorMsg, $errorMessage)) {
                $errorMessage[] = $errorMsg;
            }
            curl_close($ch);
            // sleep 3 seconds before trying again...
            sleep(3);
            continue;
        }
        curl_close($ch);

        return $responseData;
    }

    // didn't work after $maxTry attempts
    throw new RuntimeException('ERROR: '.implode(', ', $errorMessage));
}

/**
 * @return string
 */
function getDisplayName(array $serverInstance)
{
    if (array_key_exists('country_code', $serverInstance)) {
        return $serverInstance['country_code'];
    }
    if (!array_key_exists('display_name', $serverInstance)) {
        return $serverInstance['base_url'];
    }
    if (!is_array($serverInstance['display_name'])) {
        return $serverInstance['display_name'];
    }
    if (array_key_exists('en-US', $serverInstance['display_name'])) {
        return $serverInstance['display_name']['en-US'];
    }

    return array_values($serverInstance['display_name'])[0];
}

/**
 * @param string $uriStr
 *
 * @return string
 */
function removeUriPrefix($uriStr)
{
    if (0 === strpos($uriStr, 'tel:')) {
        return substr($uriStr, 4);
    }
    if (0 === strpos($uriStr, 'mailto:')) {
        return substr($uriStr, 7);
    }

    return $uriStr;
}

/**
 * @param string $mailTo
 *
 * @return void
 */
function mailErrorDiff($mailTo, $mailFrom, array $errorList)
{
    $errorHistoryFile = __DIR__.'/error_history.dat';
    $newError = [];
    $resolvedError = [];

    $errorHistory = [];
    if (file_exists($errorHistoryFile)) {
        // we have previous errors!
        $errorHistory = unserialize(file_get_contents($errorHistoryFile));
    }

    // check if we already knew about the errors in the current error list...
    foreach ($errorList as $baseUrl => $errorMsg) {
        if (!array_key_exists($baseUrl, $errorHistory)) {
            // we didn't know about it
            $newError[$baseUrl] = $errorMsg;
        }
    }

    // check for old errors that are now resolved...
    foreach ($errorHistory as $baseUrl => $errorMsg) {
        if (!array_key_exists($baseUrl, $errorList)) {
            // resolved
            $resolvedError[$baseUrl] = $errorMsg;
        }
    }

    file_put_contents($errorHistoryFile, serialize($errorList));

    if (0 === count($newError) && 0 === count($resolvedError)) {
        // nothing changed, do nothing
        return;
    }

    $mailMessage = '';
    if (0 !== count($newError)) {
        $mailMessage .= '*New Errors*'."\r\n\r\n";
        foreach ($newError as $k => $v) {
            $mailMessage .= '    '.$k.': '.$v."\r\n";
        }
        $mailMessage .= "\r\n";
    }
    if (0 !== count($resolvedError)) {
        $mailMessage .= '*Resolved Errors*'."\r\n\r\n";
        foreach ($resolvedError as $k => $v) {
            $mailMessage .= '    '.$k."\r\n";
        }
        $mailMessage .= "\r\n";
    }

    // mail the report
    mail(
        $mailTo,
        '[eduVPN] Server Status Notification',
        $mailMessage,
        "From: $mailFrom\r\nContent-Type: text/plain"
    );
}

// now retrieve the info.json file from all servers
$errorList = [];
$serverInfoList = [];
$serverCountList = [
    'secure_internet' => 0,
    'institute_access' => 0,
    'alien' => 0,
];
foreach ($serverList as $srvInfo) {
    $baseUrl = $srvInfo['base_url'];
    $serverHost = parse_url($baseUrl, PHP_URL_HOST);
    $hasIpFour = checkdnsrr($serverHost, 'A');
    $hasIpSix = checkdnsrr($serverHost, 'AAAA');
    $srvInfo = array_merge(
        $srvInfo,
        [
            'v' => null,
            'h' => $serverHost,
            'hasIpFour' => $hasIpFour,
            'hasIpSix' => $hasIpSix,
            'errMsg' => null,
            'displayName' => getDisplayName($srvInfo),
        ]
    );

    ++$serverCountList[$srvInfo['server_type']];

    try {
        $infoJson = getUrl($baseUrl.'info.json');
        $infoData = json_decode($infoJson, true);
        $baseVersion = '?';
        $versionString = '?';
        if (array_key_exists('v', $infoData)) {
            $baseVersion = $infoData['v'];
            $versionString = $infoData['v'];
            if (false !== $dashPos = strpos($versionString, '-')) {
                $baseVersion = substr($versionString, 0, $dashPos);
            }
        }
        $srvInfo['v'] = $baseVersion;
        $srvInfo['vDisplay'] = $versionString;
    } catch (RuntimeException $e) {
        $srvInfo['errMsg'] = $e->getMessage();
        $errorList[$baseUrl] = $e->getMessage();
    }

    $serverInfoList[$baseUrl] = $srvInfo;
}

if (null !== $mailTo) {
    // remove notoriously unreliable servers from the error list
    foreach ($doNotMonitorList as $baseUrl) {
        if (array_key_exists($baseUrl, $errorList)) {
            unset($errorList[$baseUrl]);
        }
    }
    mailErrorDiff($mailTo, $mailFrom, $errorList);
}

$dateTime = new DateTime();
?>
<!DOCTYPE html>

<html lang="en-US" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>eduVPN Server Info</title>
    <style>
body {
    font-family: sans-serif;
    max-width: 50em;
    margin: 1em auto;
    color: #444;
}

small {
    font-size: 75%;
    color: #888;
}

code {
    font-size: 125%;
}

h1 {
    text-align: center;
}

table {
    border: 1px solid #ccc;
    width: 100%;
    border-collapse: collapse;
}

table th, table td {
    padding: 0.8em 0.5em;
}

table thead th {
    text-align: left;
}

table tbody tr:nth-child(odd) {
    background-color: #f8f8f8;
}

ul {
    margin: 0;
    padding: 0 1em;
}

p, sup {
    font-size: 85%;
}

a {
    color: #444;
}

span.error {
    color: darkred;
}

span.success {
    color: darkgreen;
}

span.fade {
    color: lightgray;
}

span.awesome {
    color: lightgreen;
}

span.warning {
    color: darkorange;
}

footer {
    margin-top: 1em;
    font-size: 85%;
    color: #888;
    text-align: center;
}
    </style>
</head>
<body>
<h1>eduVPN Server Count</h1>
<table>
<thead>
<tr><th>Type</th><th>#Servers</th></tr>
</thead>
<tbody>
<?php foreach ($serverCountList as $serverType => $serverCount): ?>
<?php if (0 !== $serverCount): ?>
    <tr>
        <th>
<?php if ('secure_internet' === $serverType): ?>
            <span title="Secure Internet">🌍 Secure Internet</span>
<?php elseif ('institute_access' === $serverType): ?>
            <span title="Institute Access">🏛️ Institute Access</span>
<?php else: ?>
            <span title="Alien">👽 Alien</span>
<?php endif; ?>
        </th>
        <td><?=$serverCount; ?></td>
    </tr>
<?php endif; ?>
<?php endforeach; ?>
</tbody>
</table>
<h1>eduVPN Server Info</h1>
<p>The current <span class="success">STABLE</span> release is <?=$latestVersion; ?>.</p>
<table>
<thead>
    <tr>
        <th></th>
        <th>Server FQDN</th>
        <th>Version</th>
        <th>Support</th>
    </tr>
</thead>
<tbody>
<?php foreach ($serverInfoList as $baseUrl => $serverInfo): ?>
    <tr>
        <td>
<?php if ('secure_internet' === $serverInfo['server_type']): ?>
            <span title="Secure Internet">🌍</span>
<?php elseif ('institute_access' === $serverInfo['server_type']): ?>
            <span title="Institute Access">🏛️</span>
<?php else: ?>
            <span title="Alien">👽</span>
<?php endif; ?>
        </td>
        <td>
            <a href="<?=$baseUrl; ?>"><?=$serverInfo['displayName']; ?></a> <small>[<?=$serverInfo['h']; ?>]</small>
<?php if ($serverInfo['hasIpFour']): ?>
                <sup><span class="success" title="IPv4">4</span></sup>
<?php else: ?>
                <sup><span class="warning" title="No IPv4">4</span></sup>
<?php endif; ?>
<?php if ($serverInfo['hasIpSix']): ?>
                <sup><span class="success" title="IPv6">6</span></sup>
<?php else: ?>
                <sup><span class="warning" title="No IPv6">6</span></sup>
<?php endif; ?>
        </td>
        <td>
<?php if (null === $serverInfo['v']): ?>
<?php if (null !== $serverInfo['errMsg']): ?>
            <span class="error" title="<?=htmlentities($serverInfo['errMsg']); ?>">Error</span>
<?php else: ?>
            <span class="error">Error</span>
<?php endif; ?>
<?php else: ?>
<?php if ('?' === $serverInfo['v']): ?>
            <span class="warning">?</span>
<?php elseif (0 === strnatcmp($serverInfo['v'], $latestVersion)): ?>
            <span class="success"><?=$serverInfo['vDisplay']; ?></span>
<?php elseif (0 > strnatcmp($serverInfo['v'], $latestVersion)): ?>
            <span class="warning"><?=$serverInfo['vDisplay']; ?></span>
<?php else: ?>
            <span class="awesome"><?=$serverInfo['vDisplay']; ?></span>
<?php endif; ?>
<?php endif; ?>
        </td>
            <td>
<?php if (array_key_exists('support_contact', $serverInfo) && 0 !== count($serverInfo['support_contact'])): ?>
            <ul>
<?php foreach ($serverInfo['support_contact'] as $supportContact): ?>
            <li><a href="<?=$supportContact; ?>"><?=removeUriPrefix($supportContact); ?></a></li>
<?php endforeach; ?>
            </ul>
<?php else: ?>
            <span class="fade">?</span>
<?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
<p>The version <span class="warning">?</span> means the eduVPN component 
<code>vpn-user-portal</code> is older than version 
<a href="https://github.com/eduvpn/vpn-user-portal/blob/v2/CHANGES.md#214-2019-12-10">2.1.4</a>, 
the first release reporting the version. When the version is 
<span class="error">Error</span>, it means the server could not be reached, or 
there was problem establishing the (TLS) connection.
</p>
<footer>
Generated on <?=$dateTime->format(DateTime::ATOM); ?>
</footer>
</body>
</html>
