<?
/*
require_once(COMPONENTS.'/appclear/containers/FF.php');
 */
/*
 * FFv1.042 23.01.2017  FormField
		Способ применения:
		$fields = array(
			'name'=>array('type'=>'text','empty'=>false,'insertdb'=>true,'rename'=>'user_*'),
			'phone'=>array('type'=>'regex','empty'=>false,'insertdb'=>true,'rename'=>'user_*','regex'=>'/^\+?\s?\d\s?\(?\d{3}\)?\s?\d{3}(-|\s)?\d{2}(-|\s)?\d{2}$/'),
			'email'=>array('type'=>'regex','empty'=>false,'insertdb'=>true,'rename'=>'user_*', 'regex'=>'/.+@.+\..+/', 'busy'=>true),
			'about'=>array('type'=>'text','empty'=>true,'insertdb'=>true,'rename'=>'user_*'),
			'inclient'=>array('type'=>'bool','empty'=>true,'insertdb'=>true),
			'specializations'=>array('type'=>'array','empty'=>true,'insertdb'=>true,'db'=>array('method'=>'GetDocumentById','journal'=>'ref'),'rename'=>'specialization')
		);
		$this->update_fields = array();
		if($this->ff = FF::Install($fields)->GetFields($_POST)->CheckFields()){
			if($this->ff->UpdateDB(array('journal'=>'masters','path'=>'/'.$this->account['alias']))){
			}
		}
*/
class FF{
	static protected $error = null;
	protected $DB = null;
	protected $fields = null;
	public $object = null;
	public $docuemnt = null;
	public $documents = null;
	public $children = null;
	static protected $FIELDS = null;
	static protected $OBJECTS = null;
	static protected $DOCUMENT = null;
	static protected $DOCUMENTS = null;
	static protected $CHILDREN = null;
	protected function __construct($fields){
		$this->DB = &OpenDM('content', 'Common.Document'); // Подключаем местну. ORM
		$this->fields = $fields;
		if(!is_null($this->fields)) self::$FIELDS[]=&$this->fields;
	}
	// Создание элементария
	static public function Install($fields){
		return new self($fields);
	}
	/*Метод rename подставляет измененное имя вместо изначально указанного
		'name'=>array(....,'rename'=>'user_*'),
		name - изначальное и в основном указывается как это поле записано в БД
		rename - указывается если в блоке form ему дали другое имя
		* - будет заменено name
	*/
	protected function Name($name){
		if(!isset($this->fields[$name]['rename'])) return $name;
		return str_replace('*',$name,$this->fields[$name]['rename']);
	}
	// Очистка поля от мешуры
	// FFv1.04 добавлена возможность очистки по regex
	static public function CV($str, $regex=''){
		if(empty($regex)) return trim(strip_tags($str));
		if($str=FF::CV($str)) $str=preg_match($regex,$str,$arr_str)?array_shift($arr_str):'';
		return $str;
	}
	// Выводит содержимое obj методом var_dump(), если указан title то выведет его вначале
	static public function dump($obj, $title=''){
		echo '<hr><xmp>';
		if($title)echo $title;
		var_dump($obj);
		echo '</xmp><hr>';
	}
	//Возвращает объект из self::DOCUMENT
	static public function GetD($name=''){
		$doc=self::$DOCUMENT;
		return ($name?$doc[$name]:$doc);
	}
	//Возвращает объект из self::DOCUMENTS
	static public function GetDs($name=''){
		$docs=self::$DOCUMENTS;
		return ($name?$docs[$name]:$docs);
	}
	// Default settings for fields
	public function SetFields(){
		foreach($this->fields as $name=>$params){
			switch($params['type']){
				case 'enum':
					$this->object[$name] = $params['enum'][0];
					break;
				case 'bool':
					$this->object[$name] = 'true';
					break;
				default:
					$this->object[$name] = '';
			}
		}
	}
	/*
		Получение полей формы из ассцоиативного массива
	*/
	public function GetFields($data){
		foreach($this->fields as $name=>$params){
			switch($params['type']){
				case 'regex':
				case 'text':
				case 'pass':
					$this->object[$name] = self::CV($data[$this->Name($name)]);
					break;
				case 'int':
					$this->object[$name] =(int)(self::CV($data[$this->Name($name)]));
					break;
				case 'enum':
					foreach($params['enum'] as $key=>$value){
						if(self::CV($data[$this->Name($name)]) === $value)$this->object[$name] = $value;
					}
					break;
				case 'bool':
					$this->object[$name] = isset($data[$this->Name($name)]);
					break;
				case 'array':
					if(is_array($data[$this->Name($name)])){
						$clear_arr = array();
						foreach($data[$this->Name($name)] as $key=>$value){
							$clear_arr[]=self::CV($value);
						}
						$this->object[$name] = implode(';',$clear_arr);
					}else $this->object[$name] = '';/* self::AppendError($name.'.empty'); */
					break;
			}
		}
		if(!is_null($this->object))self::$OBJECTS[]=&$this->object;
		if(is_null($this->object)) self::AppendError('object.empty');
		return $this;
	}
	/*
		Метод проверки  полей
	*/
	public function CheckFields(){
		$DB = false;
		//Проверка на заполненность, если "empty=false"
		if(is_null($this->error)){
			foreach($this->fields as $name=>$params){
				if(!$params['empty']){
					if(!$this->object[$name]) self::AppendError($this->Name($name).'.empty');
				}
				// Если есть такой параметр, то проверять на существование в БД.
				if(isset($params['db']))$DB = true;
			}
		}
		// Проверка корректности
		if(is_null($this->error)){
			foreach($this->fields as $name=>$params){
				if(self::IsNotError()){
					switch($params['type']){
						case 'int':
							if(isset($params['interval'])){
								list($min,$max) = explode('-',$params['interval']);
								if((isset($min) && $this->object[$name] < $min) ||(isset($max) && $this->object[$name] > $max)) self::AppendError($this->Name($name).'.interval');
							}
							break;
						case 'array':
						case 'regex':
							if(isset($params['regex']))if(!preg_match($params['regex'], $this->object[$name])) self::AppendError($this->Name($name).'.wrong');
							break;
						case 'enum':
							if(!$params['empty']){
								$enum = true;
								foreach($params['enum'] as $key=>$value){
									if($this->object[$name] === $value)$enum = false;
								}
								if($enum) self::AppendError($this->Name($name).'.wrong',$this->object[$name]);
							}
							break;
						default:break;
					}
				}
			}
		}
		// Проверка существования совпадений, занятости в БД
		/*
			'db'=>array('method'=>'GetDocumentById','journal'='string')
		*/
		if(self::IsNotError() && $DB){
			foreach($this->fields as $name=>$params){
				if(isset($params['db'])){
					switch($params['db']['method']){
						//Нужно указать дополнительный параметр journal
						case 'GetDocumentById':
							switch($params['type']){
								case 'int':
									$this->document = $this->DB->GetDocumentById($params['db']['journal'],(int)$this->object[$name]);
									if(stripos($this->document['journal_alias'],$params['db']['journal'])===false) self::AppendError($this->Name($name).'.not.exist');
									else self::$DOCUMENT[$name]=$this->document;
									break;
								case 'array':
									foreach(explode(';',$this->object[$name]) as $key=>$id){
										$this->documents[(int)$id] = $this->DB->GetDocumentById($params['db']['journal'],(int)$id);
										if($this->documents[(int)$id]['journal_alias'] !== $params['db']['journal']) self::AppendError($this->Name($name).'.'.$id.'.not.exist');
									}
									if(!is_null($this->documents)) self::$DOCUMENTS[]=$this->documents;
									break;
							}
							break;
						case 'GetChildrenById':
							if(!$this->document = $this->DB->GetDocumentById($params['db']['journal'],(int)$this->object[$name])) self::AppendError($name.'.document.not.exist');
							else{
								if($this->document['journal_alias'] !== $params['db']['journal']) self::AppendError($name.'.not.exist');
								else self::$DOCUMENT[]=&$this->document;
								$template=is_array($params['db']['template'])?$params['db']['template']:array($this->document['journal_alias'].'.'.$params['db']['template']);
								$this->DB->Clear();
								if(!$this->children = $this->DB->Select($this->document['journal_alias'],'/'.$this->document['alias'],$template)->FStorage) self::AppendError($this->Name($name).'.not.children');
								else self::$CHILDREN[]=&$this->children;
							}
							break;
						case 'Busy':
							$this->DB->Clear();
							$this->DB->SetSearchCondition();
							$RRootRelation = &$this->DB->SetSearchRelation($this->DB->SearchCondition, 'AND');
							if(isset($params['db']['id']))$this->DB->SetSearchTerm($RRootRelation, 'd.document_id', $params['db']['id'],'<>');
							$this->DB->SetSearchTerm($RRootRelation, $params['db']['template'].'.'.$name, $this->object[$name],'LIKE');
							$result = $this->DB->Select($params['db']['journal'],'*',array($params['db']['journal'].$params['db']['template']), '', array('count'=>true));
							if((int) $result) self::AppendError($this->Name($name).'.busy');
					}
				}
			}
		}
		return $this;
	}
	// Статический метод для добавления ошибок (с версии FFv1.02 поле $error является статичным)
	static public function AppendError($str, $value = true){
		self::$error['error.'.$str] = $value;
		return is_bool($value)?false:$value;
	}
	// Оставлен для совместимости. Аналог AppendError
	public function AddError($str, $value = true){
		self::$error['error.'.$str] = $value;
	}
	// Возвращает массив ошибок
	public function GetError(){
		return self::$error;
	}
	// Cтатический метод для проверки наличия ошибок (с версии FFv1.02 поле $error является статичным)
	static public function IsNotError($success = true, $fail = false){
		return is_null(self::$error)?$success:$fail;
	}
	// Оставлен для совместимости. Аналог IsNotError
	public function HasError($success = true, $fail = false){
		return is_null(self::$error)?$success:$fail;
	}
	// Вывод всех доступных данных класса
	static public function BrowseError($debug = false){
		if(!$debug)if(self::IsNotError())return true;
		self::dump(self::$error,'error :');
		if($debug){
			session_start();
			foreach(array(
				'FIELDS: '=>self::$FIELDS,
				'OBJECTS: '=>self::$OBJECTS,
				'DOCUMENT: '=>self::$DOCUMENT,
				'DOCUMENTS: '=>self::$DOCUMENTS,
				'CHILDREN: '=>self::$CHILDREN,
				'$_GET: '=>$_GET,
				'$_POST: '=>$_POST,
				'$_SESSION: '=>$_SESSION
				) as $title=>$obj){
				self::dump($obj,$title);
			}
		}
		if($debug !== 'debug')die();
		return true;
	}
	// Возвращает ошибки в переданный DOM-объект
	static public function ReturnErrorInDOM($MainCN){
		if(!is_a($MainCN,'RmlNode')){
			self::AppendError('ReturnErrorInDOM.conteiner.isnot.RmlNode');
			return false;
		}
		if(self::IsNotError()) return false;
		$ErrorCN = &$MainCN->AddNode('Error');
		foreach(self::$error as $name=>$t){
			$ErrorCN->SetAttribute($name,'true');
		}
		return true;
	}
	// Возвращает поля формы в переданный RmlNode-объект.
	public function ReturnAllValueField($MainCN){
		if(!is_a($MainCN,'RmlNode')){
			self::AppendError('ReturnAllValueField.conteiner.isnot.RmlNode');
			return false;
		}
		$FieldCN = &$MainCN->AddNode('Field');
		foreach($this->object as $name=>$value){
			$FieldCN->SetAttribute($this->Name($name),$value);
		}
		return true;
	}
	/*
	Вставляет документы в БД.
	Параметры:
		'journal'=>'string',
		'template'=>'string',
		'path'=>'string',
		['additional_fields'=>'array']
	*/
	public function InsertDB($info){
		if(!$info) return self::AppendError('insert.not.params');
		$document_params['alias.editable'] = 'false';
		$document_field = array();
		foreach($this->fields as $name=>$params){
			if($params['insertdb']){
				switch($params['type']){
					case 'bool':
						$document_field[$name] = $this->object[$name]?'true':'false';
						break;
					default:
						$document_field[$name] = $this->object[$name];
						break;
				}
			}
		}
		if(isset($info['additional_fields'])){
			foreach($info['additional_fields'] as $name=>$value){
				$document_field[$name] = $value;
			}
		}
		if(!count($document_field)) return self::AppendError('insertdb.fields.document.empty');
		if(!$result=$this->DB->Insert($info['journal'].'.'.$info['template'],$info['path'],$document_field,$document_params)) self::AppendError('insert.db');
		return self::IsNotError((int)$result);
	}
	/*
	Обновляет/заменяет поля документа в БД
	Параметры:
		'journal'=>'string',
		'path'=>'/string',
		'additional_fields'=>'array(name_db=>value,...)'
	*/
	public function UpdateDB($info){
		if(!$info) return self::AppendError('update.not.params');
		$document_field = array();
		foreach($this->fields as $name=>$params){
			if($params['insertdb']){
				switch($params['type']){
					case 'bool':
						$document_field[$name] = $this->object[$name]?'true':'false';
						break;
					default:
						$document_field[$name] = $this->object[$name];
						break;
				}
			}
		}
		if(isset($info['additional_fields'])){
			foreach($info['additional_fields'] as $name=>$value){
				$document_field[$name] = $value;
			}
		}
		if(isset($info['debug'])) self::dump(array(
			'info: '=>$info,
			'document_field:'=>$document_field
		),'UpdateDB: ');
		if(!$result=$this->DB->Update($info['journal'],$info['path'],$document_field)) self::AppendError('update.db');
		return self::IsNotError((int)$result);
	}
	/*
	Стат метод изменения полей документа в БД
	Параметры:
		$fields=array(
			name_db=>value,
			...
		),
		$params=array(
			'journal'=>'string',
			'path'=>'/string'
		)
	*/
	static public function UpFields($fields,$params){
		if(!is_array($fields) || !count($fields)) return self::AppendError('.fields.empty');
		if(!is_array($params) || !count($params)) return self::AppendError('.params.empty');
		if(!$result=OpenDM('content', 'Common.Document')->Update($params['journal'],$params['path'],$fields)) return self::AppendError('result');
		return self::IsNotError($result);
	}
	/*
	Отправка почты пользователской части
	Шаблон сообщения, Тема сообщения, кому(Майл, Имя), поля для шаблона
	*/
	public function SendUserMail($template_mail,$MsgSubj,$recipient, $fields){
		if(!count($fields)) return self::AppendError('send.user.mail.not.fields');
		$this->HtmlMail = &OpenDM('kernel', 'Common.HtmlMail');
		$tpl = '';
		if(file_exists($_SERVER['DOCUMENT_ROOT'].'/content/'.$GLOBALS['cfg']['project']['alias'].'/templates/'.$template_mail.'.htm')){
			$handle = fopen($_SERVER['DOCUMENT_ROOT'].'/content/'.$GLOBALS['cfg']['project']['alias'].'/templates/'.$template_mail, 'r');
			while(!feof($handle)) $tpl.= fgets($handle, 4096);
			fclose($handle);
			$Parser = new CParser();
			foreach($fields as $name=>$value){
				$Parser->SetVariable($name,$value);
			}
			$MsgBody = stripslashes($Parser->Process($tpl));
			$MsgTo = '"'.$recipient['name'].'" <'.$recipient['email'].'>';
			$MsgFrom = '"'.$GLOBALS['cfg']['mailbot']['from']['name'].'" <'.$GLOBALS['cfg']['mailbot']['from']['email'].'>';
			$this->HtmlMail->Clear();
			$this->HtmlMail->add_html($MsgBody);
			$send_result = $this->HtmlMail->send(
				$GLOBALS['cfg']['mailbot']['from']['server'],
				$recipient['email'],
				$MsgFrom,
				$MsgSubj,
				'',
				$GLOBALS['cfg']['mailbot']['from']['email']
			);
		}else self::AppendError('template..user.not.exist');
		if($send_result !== true) self::AppendError('send.user',$send_result);
		return self::IsNotError();
	}
	/*
	Отправка почты админской части
	Доступ, Шаблон сообщения, Тема сообщения, поля для шаблона
	*/
	public function SendAdminMail($access_notify, $template_mail, $MsgSubj, $fields){
		if(!count($fields)) return self::AppendError('send.admin.mail.not.fields');
		$HtmlMail = &OpenDM('kernel', 'Common.HtmlMail');
		$PublicUser = &OpenDM('access', 'Public.User');
		$CommonUser = &OpenDM('access', 'Common.User');
		if(file_exists($_SERVER['DOCUMENT_ROOT'].'/content/'.$GLOBALS['cfg']['project']['alias'].'/templates/'.$template_mail.'.htm')){
			$tpl = file_get_contents($_SERVER['DOCUMENT_ROOT'].'/content/'.$GLOBALS['cfg']['project']['alias'].'/templates/'.$template_mail.'.htm');
			$UserRS = $PublicUser->Browse();
			if($UserRS->Count()){
				$UserRS->First();
				while($record = $UserRS->Next()){
					if($CommonUser->CheckPermission('component.appclear.'.$access_notify,(int)$record->GetValue('user_id'))){
						$emailTo = $record->GetValue('user_email');
						$Parser = new CParser();
						foreach($fields as $name=>$value){
							$Parser->SetVariable($name, $value);
						}
						$MsgBody = $Parser->Process($tpl);
						$HtmlMail->Clear();
						$HtmlMail->add_html($MsgBody);
						$send_result = $HtmlMail->send(
							$GLOBALS['cfg']['mailbot']['from']['server'],
							$emailTo,
							$MsgFrom,
							$MsgSubj,
							'',
							$GLOBALS['cfg']['mailbot']['from']['email']);
					}
				}
			}
		}else self::AppendError('template.admin.not.exist');
		if($send_result !== true) self::AppendError('send.admin',$send_result);
		return self::IsNotError();
	}
}