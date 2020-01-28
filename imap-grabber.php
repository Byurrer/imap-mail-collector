<?php

ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

header("Content-type: text/plain; charset=utf-8;");

//##########################################################################

/*! извлечение исходников писем из email
	@param sEmail адрес электронной почты
	@param sPassword пароль для входа по imap
	@param sImap адрес imap сервера вместе с протоколом
	@param canDelete надо ли удалять письма
	@param fnHandler функция обработчик найденного сообщения, если null тогда будет просто подсчет данных
	@return [
		"dirs" => массив строк директорий,
		"count_msgs" => количество сообщений на ящике,
		"time" => время выполнения функции в секундах,
	]
*/
function ImapGrabber($sEmail, $sPassword, $sImap, $canDelete, $fnHandler=null)
{
	$fTime = microtime(true);

	$iCountMsgs = 0;

	$hCurl = curl_init();
	curl_setopt($hCurl, CURLOPT_USERNAME, $sEmail);
	curl_setopt($hCurl, CURLOPT_PASSWORD, $sPassword);
	curl_setopt($hCurl, CURLOPT_URL, $sImap);
	curl_setopt($hCurl, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($hCurl, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($hCurl, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($hCurl, CURLOPT_TIMEOUT, 10);
	curl_setopt($hCurl, CURLOPT_FOLLOWLOCATION, TRUE);

	$sReply = curl_exec($hCurl);
	/*echo  "\n" . curl_errno($hCurl) . " - " . curl_strerror(curl_errno($hCurl)) . "\n";
	echo("imap response: ".$sReply) . "\n\n";*/

	$sReply = str_replace("\r\n", "\n", $sReply);

	preg_match_all("/\"(.[^\"]*?)\"?\n/ims", $sReply, $aMatches);

	$aDataMsgs = [];
	$aDirs = [];
	foreach($aMatches[1] as $value)
	{
		$value = trim($value);

		//https://ru.wikipedia.org/wiki/UTF-7
		$sDir = mb_convert_encoding($value, "UTF-8", "UTF7-IMAP");
		$aDirs[] = $sDir;

		//получение порядковых номеров всех неудаленных писем
		curl_setopt($hCurl, CURLOPT_URL, "$sImap/$value/");
		curl_setopt($hCurl, CURLOPT_CUSTOMREQUEST, "SEARCH ALL UNDELETED");
		$sReply = curl_exec($hCurl);
		//echo("$sImap/$value/" . "reply: ".$sReply."\n");
		$sReply = str_replace("\r\n", "\n", $sReply);
		$sReply = mb_substr($sReply, mb_strlen("* SEARCH "));
		$aStrs = explode(" ", $sReply);
		
		if(count($aStrs) == 1 && mb_strlen(trim($aStrs[0])) == 0)
			continue;
		
		foreach($aStrs as $iNum)
		{
			$iNum = intval($iNum);
			if(!is_numeric($iNum) || $iNum == 0)
				continue;

			++$iCountMsgs;
			if($fnHandler === null)
				continue;
				
			//получение исходника письма
			curl_setopt($hCurl, CURLOPT_URL, "$sImap/$value/;UID=$iNum");
			curl_setopt($hCurl, CURLOPT_CUSTOMREQUEST, NULL);
			$sReply = curl_exec($hCurl);
			$sReply = str_replace("\r\n", "\n", $sReply);

			//если данные будут паковаться в json, тогда надо base64_encode($sReply), иначе может быть: Malformed UTF-8 characters, possibly incorrectly encoded
			$aData = [
				"num" => $iNum,
				"raw" => $sReply,
				"dir" => $sDir,
				"email" => $sEmail
			];

			$fnHandler($aData);
		}

		//если надо удалять письма
		if($canDelete)
		{
			curl_setopt($hCurl, CURLOPT_URL, "$sImap/$value/");
			foreach($aStrs as $iNum)
			{
				//получение UID
				curl_setopt($hCurl, CURLOPT_CUSTOMREQUEST, "FETCH $iNum UID");
				$sReply = curl_exec($hCurl);
				
				//извлечение UID из ответа
				preg_match("/UID\s?(\d+)/", $sReply, $aNumber);
				$UID = $aNumber[1];

				//пометка письма флагом удаления
				curl_setopt($hCurl, CURLOPT_CUSTOMREQUEST, "UID STORE {$UID} +Flags \Deleted");
				$sReply = curl_exec($hCurl);
			}
		}
	}

	//для применения удаления делаем EXPURGE
	curl_setopt($hCurl, CURLOPT_CUSTOMREQUEST, "EXPURGE");
	$sReply = curl_exec($hCurl);
	curl_close($hCurl);

	return [
		"dirs" => $aDirs,
		"count_msgs" => $iCountMsgs,
		"time" => microtime(true) - $fTime,
	];
}
