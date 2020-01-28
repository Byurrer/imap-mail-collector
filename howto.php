<?php

include("imap-grabber.php");

/*! обработчик в случае если найдено сообщение, сохранит сообщение в директорию с названием email на сервере
	@param aMsg массив: [
		"num" => порядковый номер сообщения в директории,
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

	$sFile = $sDir."/".$aMsg["num"].".txt";
	file_put_contents($sFile, $aMsg["raw"]);
}

//##########################################################################

$aData = ImapGrabber("", "", "", false, "SaveMail");
print_r($aData);
