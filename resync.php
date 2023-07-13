<?php
/**
 * @revision      $Id$
 * @created       Apr 16, 2016
 * @package       ISPConfig
 * @category      Tools
 * @version       1.0.0
 * @desc          Synchonization tool
 * @copyright     Copyright Alexey Gordeyev IK © 2016 - All rights reserved.
 * @license       MIT
 * @author        Alexey Gordeyev IK <aleksej@gordejev.lv>
 * @link          http://www.gordejev.lv/
 * @source        http://code.google.com/p/ag-php-classes/wiki/ImagesHelper
 */

function setupEnvironmentVars()
{
    $env_file_path = realpath(__DIR__ . "/.env");
    //Check .envenvironment file exists
    if (!is_file($env_file_path)) {
        throw new ErrorException("Environment File is Missing.");
    }
    //Check .envenvironment file is readable
    if (!is_readable($env_file_path)) {
        throw new ErrorException("Permission Denied for reading the " . ($env_file_path) . ".");
    }
    //Check .envenvironment file is writable
    if (!is_writable($env_file_path)) {
        throw new ErrorException("Permission Denied for writing on the " . ($env_file_path) . ".");
    }

    $var_arrs = array();
    // Open the .en file using the reading mode
    $fopen = fopen($env_file_path, 'r');
    if ($fopen) {
        //Loop the lines of the file
        while (($line = fgets($fopen)) !== false) {
            // Check if line is a comment
            $line_is_comment = (substr(trim($line), 0, 1) == '#') ? true : false;
            // If line is a comment or empty, then skip
            if ($line_is_comment || empty(trim($line)))
                continue;

            // Split the line variable and succeeding comment on line if exists
            $line_no_comment = explode("#", $line, 2)[0];
            // Split the variable name and value
            $env_ex = preg_split('/(\s?)\=(\s?)/', $line_no_comment);
            $env_name = trim($env_ex[0]);
            $env_value = isset($env_ex[1]) ? trim($env_ex[1]) : "";
            $var_arrs[$env_name] = $env_value;
        }
        // Close the file
        fclose($fopen);
    }
    foreach ($var_arrs as $name => $value) {
        //Using putenv()
        //putenv("{$name}={$value}");
        //Or, using $_ENV
        $_ENV[$name] = $value;
        // Or you can use both
    }
}

try {
    setupEnvironmentVars();
    echo "Environment Variables loaded successfully \n";
} catch (ErrorException $e) {
    echo $e->getMessage();
    die;
}

/**
 * create connection to ispconfig database
 */
echo "Connecting to the ISPConfig Database..... \n";
$dbsrc = new mysqli($_ENV['SRC_HOST'], $_ENV['SRC_USER'], $_ENV['SRC_PASSWORD'], $_ENV['SRC_DATABASE']);
if ($dbsrc->connect_error) {
    die('(ISPConfig) DB Source Connect Error (' . $dbsrc->connect_errno . ') ' . $dbsrc->connect_error);
}
echo "Connected! \n";

/**
 * create connection to powerdns database
 */
echo "Connecting to the PowerDSN Database..... \n";
$dbdst = new mysqli($_ENV['PDNS_HOST'], $_ENV['PDNS_USER'], $_ENV['PDNS_PASSWORD'], $_ENV['PDNS_DATABASE']);
if ($dbdst->connect_error) {
    die('(PowerDNS) DB Destination Connect Error (' . $dbdst->connect_errno . ') ' . $dbdst->connect_error);
}
echo "Connected! \n";

echo "Deleting old ISPConfig records from PowerDNS DB... \n";
// delete old records from powerdns database
$sql = 'DELETE FROM `records` WHERE `ispconfig_id` IS NOT NULL';
if ($dbdst->query($sql) === TRUE) {
    echo "Old records deleted successfully! \n";
} else {
    die("Error deleting record: " . $dbdst->error);
}


$domains = array();
$records = array();

/**
 * select SOA records from dns_soa table (ispconfig)
 */
echo "Getting domains from ISPConfig Database.... \n";
$domains_result = $dbsrc->query('SELECT * FROM `dns_soa` ORDER BY `id`', MYSQLI_USE_RESULT);

if ($domains_result) {
    $domainCount = 0;
    while ($row = $domains_result->fetch_assoc()) {
        $domain = substr($row['origin'], -1) === '.' ? substr($row['origin'], 0, -1) : $row['origin'];
        $email = substr($row['mbox'], -1) === '.' ? substr($row['mbox'], 0, -1) : $row['mbox'];
        $nameserver = substr($row['ns'], -1) === '.' ? substr($row['ns'], 0, -1) : $row['ns'];
        $domains[$row['id']] = array(
            'id' => $row['id'],
            'name' => $domain,
            'type' => 'MASTER',
            'notified_serial' => $row['serial']
        );
        $records[] = array(
            'ispconfig_id' => 1,
            'domain_id' => $row['id'],
            'name' => $domain,
            'type' => 'SOA',
            'content' => $nameserver . ' ' . $email . ' ' . $row['serial'] . ' ' . $row['refresh'] . ' ' . $row['retry'] . ' ' . $row['expire'] . ' ' . $row['minimum'],
            'ttl' => $row['ttl'],
            'disabled' => ($row['active'] === 'Y' ? 0 : 1),
            'prio' => 0,
            'auth' => 1
        );
        // printf("%s %s\n", $row['id'], $domain);
        $domainCount++;
    }
    echo "There are ".$domainCount . " domain results! \n";
    $domains_result->free();
} else {
    echo "There are no domain results! \n";
}

/**
 * select all records from dns_rr table (ispconfig)
 */
echo "Getting records from ISPConfig Database.... \n";
$records_result = $dbsrc->query('SELECT * FROM `dns_rr` ORDER BY `id`', MYSQLI_USE_RESULT);

if ($records_result) {
    $recordsCount = 0;
    while ($row = $records_result->fetch_assoc()) {
        $domain = substr($row['name'], -1) === '.' ? substr($row['name'], 0, -1) : $row['name'];
        $content = substr($row['data'], -1) === '.' ? substr($row['data'], 0, -1) : $row['data'];
        $parent = $domains[$row['zone']];
        if (!preg_match('/' . $parent['name'] . '/i', $row['name'])) {
            $domain .= '.' . $parent['name'];
        }
        $records[] = array(
            'ispconfig_id' => $row['id'],
            'domain_id' => $row['zone'],
            'name' => $domain,
            'type' => $row['type'],
            'content' => $content,
            'ttl' => $row['ttl'],
            'prio' => $row['aux'],
            'disabled' => ($row['active'] === 'Y' ? 0 : 1),
            'auth' => 1
        );
        // printf("%s %s\n", $row['zone'], $domain);
        $recordsCount++;
    }
    echo "There are ".$recordsCount. " record results! \n";
    $records_result->free();
} else {
    echo "There are no record results! \n";
}

$dbdst->begin_transaction();

if (count($domains) > 0) {
    echo "Adding ISPConfig domains to PowerDNS Database.... \n";
    foreach ($domains as &$domain) {
        //
        $dsql = 'INSERT INTO `domains` '
            . ' (`id`, `name`, `type`, `notified_serial`) VALUES '
            . ' (' . $domain['id'] . ',"' . $domain['name'] . '","' . $domain['type'] . '",' . $domain['notified_serial'] . ') '
            . ' ON DUPLICATE KEY UPDATE '
            . ' `name` = "' . $domain['name'] . '",'
            . ' `type` = "' . $domain['type'] . '",'
            . ' `notified_serial` = ' . $domain['notified_serial'] . ';';

        if (!$dbdst->query($dsql)) {
            printf("Insert domain error: %s\n", $dbdst->error);
        }
    }
}

if (count($records) > 0) {
    echo "Adding ISPConfig records to PowerDNS Database.... \n";
    foreach ($records as &$record) {
        if ($record['ispconfig_id'] == null) {
            $record['ispconfig_id'] = 1;
        }
        //
        $dsql = 'INSERT INTO `records` '
            . ' (`ispconfig_id`, `domain_id`, `name`, `type`, `content`, `ttl`, `prio`, `change_date`, `disabled`, `auth`) VALUES '
            . ' (' . $record['ispconfig_id'] . ',' . $record['domain_id'] . ',"' . $record['name'] . '","' . $record['type']
            . '","' . $record['content'] . '", ' . $record['ttl'] . ', ' . $record['prio']
            . ', NOW(),' . $record['disabled'] . ',' . $record['auth'] . ' ) '
            . ' ON DUPLICATE KEY UPDATE '
            . ' `ispconfig_id` = ' . $record['ispconfig_id'] . ','
            . ' `domain_id` = ' . $record['domain_id'] . ','
            . ' `name` = "' . $record['name'] . '",'
            . ' `type` = "' . $record['type'] . '",'
            . ' `content` = "' . $record['content'] . '",'
            . ' `ttl` = ' . $record['ttl'] . ','
            . ' `prio` = ' . $record['prio'] . ','
            . ' `change_date` = NOW(),'
            . ' `disabled` = ' . $record['disabled'] . ','
            . ' `auth` = ' . $record['auth'] . ';';

        if (!$dbdst->query($dsql)) {
            printf("Insert record error: %s\n", $dbdst->error);
        }
    }
}

$dbdst->commit();
$dbsrc->close();
$dbdst->close();
echo "Sync finished successfully! \n";
