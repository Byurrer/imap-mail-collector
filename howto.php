<?php
/**
 * howto.php for imap-mail-collector
 * пример использования сборщика писем с почтового ящика по imap протоколу
 * PHP Version 7.4
 * 
 * @author Buturlin Vitaliy (Byurrer), email: byurrer@mail.ru, site: byurrer.ru
 * @copyright 2020 Buturlin Vitaliy
 * @license MIT https://opensource.org/licenses/mit-license.php
 */

ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

header("Content-type: text/plain; charset=utf-8;");

//##########################################################################

include("imap-mail-collector.php");

/*! обработчик в случае если найдено сообщение, сохранит сообщение в директорию с названием email на сервере
	@param aMsg массив: [
		"uid" => уникальный числовой идентификатор сообщения,
		"raw" => исходник письма,
		"dir" => директория письма,
		"email" => email письма
	]
*/
function SaveMail($aMsg)
{
	$sEmail = $aMsg['email'];
	if(!file_exists($sEmail))
		mkdir($sEmail);

	$sDir = "$sEmail/".$aMsg["dir"];
	if(!file_exists($sDir))
		mkdir($sDir);

	$sFile = $sDir."/".$aMsg["uid"].".txt";
	file_put_contents($sFile, $aMsg["raw"]);
}

//##########################################################################

//сборка писем с передачей данных для imap сервера и обработчика
$aData = ImapMailCollector("box@domain.zone", "password", "imaps://imap.domain.zone", false, "SaveMail");

//вернет информацию о ящике
//$aData = ImapMailCollector("box@domain.zone", "password", "imaps://imap.domain.zone");

print_r($aData);
