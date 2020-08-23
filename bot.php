<?php
require "db.php";
require_once('simplevk-master/autoload.php');

use DigitalStar\vk_api\VK_api as vk_api;
use DigitalStar\vk_api\VkApiException;

const VK_KEY = "";  // Ключ доступа
const CONFIRM_STR = "";  // Строка, которую должен вернуть сервер
const VERSION = "5.103"; // Версия API VK

$vk = vk_api::create(VK_KEY, VERSION)->setConfirm(CONFIRM_STR);
$data = json_decode(file_get_contents('php://input'));
$vk->sendOK();

// ~ Переменные ~
$peer_id = $data->object->message->peer_id;
$id = $data->object->message->from_id;
$msg_id = $data->object->message->id;
$message = $data->object->message->text;
$msg = mb_strtolower($message);
$messages = explode(" ", $message);
$cmd = mb_strtolower(str_replace(array("/", "!"), "", $messages[0]));
$args = array_slice($messages, 1);

$admins = [1234, 5678]; // Заменить на id администраторов

if($data->type == 'message_new'){
	$user = R::findOne('users', 'user_id = ?', [$id]);
	if(!$user){
		if($id == '' || $id == null){
			exit;
		}
		$user = R::dispense('users');
		$user->user_id = $id;
		$user->id_interl = null;
		R::store($user);
		$newUser = true;
	}

	// Администратор
	if(in_array($user['user_id'], $admins)){
		$mshArray = explode(' ', $message);
		if($mshArray[0] == '!рассылка'){
			$text = implode(' ', array_slice($mshArray, 1));
			$attachments = [];
			$attachmentDataS = $data->object->message->attachments;
			foreach ($attachmentDataS as $attachmentData) {
			  $attachType = $attachmentData->type;
			  if(in_array($attachType, ['photo', 'video', 'audio', 'poll'])){
			    $ownerId = $attachmentData->$attachType->owner_id;
			    $fileId = $attachmentData->$attachType->id;
			    $acess_key = $attachmentData->$attachType->access_key;
			    $file = "{$attachType}{$ownerId}_{$fileId}_{$acess_key}";
			    array_push($attachments, $file);
			  }
			}
			$allUsers = R::find('users');
			foreach ($allUsers as $getUser) {
				$checkAllowed = $vk->request('messages.isMessagesFromGroupAllowed', ['group_id' => $group_id, 'user_id' => $getUser['user_id']]);
			  if($checkAllowed['is_allowed'] != '0'){
					$vk->request('messages.send', ['peer_id' => $getUser['user_id'], 'message' => $message, "attachment" => implode(',', $attachments)]);
				}
			}
			exit;
		}
	}
	//-------------
	if($peer_id > 2000000000){ // Если сообщение в беседе
	  exit;
	}
	// ~ Кнопки ~
	$start = $vk->buttonText('Найти собеседника', 'blue', ['command' => 'start']);
	$stop = $vk->buttonText('Закончить диалог', 'blue', ['command' => 'stop']);
	// Поверка на кнопку
	if(isset($data->object->message->payload)){
	  $payload = json_decode($data->object->message->payload, True);
	}else{
	  $payload = null;
	}
	$payload = $payload['command'];

	if($newUser == true){
	  $vk->sendButton($peer_id, "🤖 Тыкни кнопку, человек", [[$start]]);
	  exit;
	}
	if($payload == 'start' || in_array($msg, ['start', 'старт', 'начать', 'найти', 'найти собеседника'])){
	  if($user['id_interl'] == 'find'){
	    $vk->sendMessage($peer_id, '🤖 Уже ищу');
	    exit;
	  }
	  $interlocutor = R::findOne('users', 'id_interl = ?', ['find']); // Ищем свободного собеседника, который нажал поиск
	  if($interlocutor['user_id'] == $id){
	    exit;
	  }
	  if($interlocutor){
	    $user['id_interl'] = $interlocutor['user_id'];
	    $interlocutor['id_interl'] = $user['user_id'];
	    R::store($user);
	    R::store($interlocutor);
	    $vk->sendButton($peer_id, "🤖 Я нашел. Можете говорить", [[$stop]]);
	    $vk->sendButton($interlocutor['user_id'], "🤖 Я нашел. Можете говорить", [[$stop]]);
	  }else{
	    $user['id_interl'] = 'find';
	    R::store($user);
	    $vk->sendButton($peer_id, "🤖 Ищу..", [[$stop]]);
	  }
	}
	if($payload == 'stop' || in_array($msg, ['stop', 'стоп', 'закончить', 'закрыть', 'покинуть собеседника'])){
		if($user['id_interl'] == 'find'){
			$vk->sendButton($peer_id, '🤖 Остановил поиск', [[$start]]);
	    $user['id_interl'] = null;
	    R::store($user);
	    exit;
	  }
		if($user['id_interl'] == null || $user['id_interl'] == ''){
			$vk->sendButton($peer_id, '🤖 Закончено', [[$start]]);
		}
		$interlocutor = R::findOne('users', [$user['id_interl']]);
		$interlocutor['id_interl'] = null;
	  R::store($interlocutor);
		$vk->sendButton($interlocutor['user_id'], '🤖 Вас покинули. Как жаль. Найти еще?', [[$start]]);
		$user['id_interl'] = null;
	  R::store($user);
		$vk->sendButton($peer_id, '🤖 Закончено', [[$start]]);
		exit;
	}
	if (mb_substr($message, 0, 1) == '#') {
		if($user['vote'] == null){
			$vote = mb_substr($message, 1);
			$user['vote'] = $vote;
			R::store($user);
			$vk->sendMessage($peer_id, "Ваш голос засчитан");
		}else{
			$vk->sendMessage($peer_id, "Вы уже проголосовали!");
		}
	}
	if($user['id_interl'] != null && $user['id_interl'] != '' && $payload != 'start' && $payload != 'stop'){
		$interlocutor = R::findOne('users', 'user_id = ?', [$user['id_interl']]);
		if(empty($data->object->message->attachments[0])){
			$vk->sendMessage($interlocutor['user_id'], $message);
		}elseif($data->object->message->attachments[0]->type == 'sticker'){
			$stickerImage = "{$data->object->message->attachments[0]->sticker->images[1]->url}";
			$fileName = "{$id}_{$data->object->message->attachments[0]->type}_{$data->object->message->attachments[0]->sticker->sticker_id}.jpg";
			file_put_contents('./'.$fileName, file_get_contents("{$stickerImage}"));
			$vk->sendImage($interlocutor['user_id'], __DIR__ . "/{$fileName}");
			unlink($fileName);
			//$vk->request('messages.send', ['peer_id' => $interlocutor['user_id'], 'sticker_id' => $data->object->message->attachments[0]->sticker->sticker_id]);
		}elseif($data->object->message->attachments[0]->type == 'audio_message'){
			$attachType = $data->object->message->attachments[0]->type;
			$ownerId = $data->object->message->attachments[0]->audio_message->owner_id;
			$fileId = $data->object->message->attachments[0]->audio_message->id;
			$acess_key = $data->object->message->attachments[0]->audio_message->access_key;
			$fileName = "{$attachType}{$ownerId}_{$fileId}_{$acess_key}.mp3";
			$urlFile = $data->object->message->attachments[0]->audio_message->link_mp3;
			file_put_contents('./'.$fileName, file_get_contents("{$urlFile}"));
			$vk->sendVoice($interlocutor['user_id'], __DIR__ . "/{$fileName}");
			unlink($fileName);
		}else{
			$attachmentDataS = $data->object->message->attachments;
	    $attachments = [];
	    foreach ($attachmentDataS as $attachmentData){
	        $attachType = $attachmentData->type;
					if($attachType == 'photo'){
						$fileName = "{$id}_photo_{$attachmentData->photo->id}.jpg";
						file_put_contents('./'.$fileName, file_get_contents("{$attachmentData->photo->sizes[count($attachmentData->photo->sizes) - 1]->url}"));
						$vk->sendImage($interlocutor['user_id'], __DIR__ . "/{$fileName}");
					}
	        if (in_array($attachType, ['video', 'audio', 'poll'])){
	            $ownerId = $attachmentData->$attachType->owner_id;
	            $fileId = $attachmentData->$attachType->id;
	            $acess_key = $attachmentData->$attachType->access_key;
	            $file = "{$attachType}{$ownerId}_{$fileId}_{$acess_key}";
	            array_push($attachments, $file);
	        }
	    }
			$vk->request('messages.send', ['peer_id' => $interlocutor['user_id'], 'message' => $message, "attachment" => implode(',', $attachments) ]);
		}
	  $vk->sendMessage($peer_id, "Отправлено");
	}
}
?>
