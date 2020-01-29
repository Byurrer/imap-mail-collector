<?php
/**
 * imap-mail-collector.php
 * сборка писем с почтового ящика через imap протокол
 * PHP Version 7.4
 * 
 * @author Buturlin Vitaliy (Byurrer), email: byurrer@mail.ru, site: byurrer.ru
 * @copyright 2020 Buturlin Vitaliy
 * @license MIT https://opensource.org/licenses/mit-license.php
 */

//##########################################################################

//! завершение программы и показ сообщения с ошибкой
function CurlError($hCurl, $sResponse)
{
	exit("\nERROR: \nurl: ".curl_getinfo($hCurl, CURLINFO_EFFECTIVE_URL)."\ncurl data:" . curl_errno($hCurl) . " - " . curl_strerror(curl_errno($hCurl)) . "\nresponse: ".$sResponse . "\n");
}

/*! извлечение исходников писем из email, если указан fnHandler, иначе просто вернет инфу о ящике
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
function ImapMailCollector($sEmail, $sPassword, $sImap, $canDelete=false, $fnHandler=null)
{
	$fTime = microtime(true);

	if($sImap[strlen($sImap)-1] == '/')
		$sImap = substr($sImap, 0, strlen($sImap)-1);

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

	$sResponse = curl_exec($hCurl);
	if(curl_errno($hCurl) != CURLE_OK)
		CurlError($hCurl, $sResponse);
	//exit($sResponse);
	$sResponse = str_replace("\r\n", "\n", $sResponse);

	//извлечение названия всех директорий
	preg_match_all("/\"(.[^\"]*?)\"?\n/ims", $sResponse, $aMatches);

	$aDataMsgs = [];
	$aDirs = [];
	foreach($aMatches[1] as $value)
	{
		$value = trim($value);
		//https://ru.wikipedia.org/wiki/UTF-7
		$sDir = mb_convert_encoding($value, "UTF-8", "UTF7-IMAP");

		/*
		если в названии директории есть пробелы и иные недопустимые символы, то надо кодировать
		https://public-inbox.org/git/20171129171301.l3coiflkfyy533yz@NUC.localdomain/t/
		*/
		$value = curl_escape($hCurl, $value);

		$aDirs[] = $sDir;

		//получение порядковых номеров всех неудаленных писем в директории
		curl_setopt($hCurl, CURLOPT_URL, "$sImap/$value");
		curl_setopt($hCurl, CURLOPT_CUSTOMREQUEST, "SEARCH ALL UNDELETED");
		$sResponse = curl_exec($hCurl);
		if(curl_errno($hCurl) != CURLE_OK)
			CurlError($hCurl, $sResponse);

		$sResponse = str_replace("\r\n", "\n", $sResponse);
		$sResponse = mb_substr($sResponse, mb_strlen("* SEARCH "));

		//парсим строку извлекая порядковые номера сообщений в директории
		$aStrs = explode(" ", $sResponse);

		//сортировка массива по убыванию, чтобы удалять не сбивая порядковые номера
		arsort($aStrs);

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

			//получение UID сообщения
			curl_setopt($hCurl, CURLOPT_URL, "$sImap/$value/");
			curl_setopt($hCurl, CURLOPT_CUSTOMREQUEST, "FETCH $iNum UID");
			$sResponse = curl_exec($hCurl);
			if(curl_errno($hCurl) != CURLE_OK)
				CurlError($hCurl, $sResponse);

			preg_match("/UID\s?(\d+)/", $sResponse, $aNumber);
			$UID = $aNumber[1];
				
			/*
			получение исходника письма 
			если заюзать num тогда будет 78 - Remote file not found, 
			хотя вчера это еще работало ... и на соседнем ресурсе это тоже работает
			*/
			curl_setopt($hCurl, CURLOPT_URL, "$sImap/$value/;UID=$UID");
			curl_setopt($hCurl, CURLOPT_CUSTOMREQUEST, NULL);
			$sResponse = curl_exec($hCurl);
			if(curl_errno($hCurl) != CURLE_OK)
				CurlError($hCurl, $sResponse);

			$sResponse = str_replace("\r\n", "\n", $sResponse);

			/*
			если данные будут паковаться в json, тогда надо base64_encode($sResponse), 
			иначе может быть: Malformed UTF-8 characters, possibly incorrectly encoded
			*/
			$aData = [
				"uid" => $UID,
				"raw" => $sResponse,
				"dir" => $sDir,
				"email" => $sEmail
			];

			$fnHandler($aData);

			//если надо удалять письмо
			if($canDelete)
			{
				//пометка письма флагом удаления
				curl_setopt($hCurl, CURLOPT_CUSTOMREQUEST, "UID STORE {$UID} +Flags \Deleted");
				$sResponse = curl_exec($hCurl);
				if(curl_errno($hCurl) != CURLE_OK)
					CurlError($hCurl, $sResponse);
			}
			
		}
	}

	//для применения удаления надо EXPURGE
	curl_setopt($hCurl, CURLOPT_CUSTOMREQUEST, "EXPURGE");
	$sResponse = curl_exec($hCurl);
	curl_close($hCurl);

	return [
		"dirs" => $aDirs,
		"count_msgs" => $iCountMsgs,
		"time" => microtime(true) - $fTime,
	];
}
