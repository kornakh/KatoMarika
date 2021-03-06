<?php
define('VERSION', 'v1.0');
//Script PHP pour un bot IRC en français sur un serveur utilisant ChanServ gérant différents aspects tels que l'autokick pour flood,
//l'autorisation de parler sur un channel modéré,...
//doit avoir les droits OP et les droits de set les flags (donc le flag +F, le mieux est qu'il ait tout les flags)
//par Kornakh

//set les variables de connexion
$config = parseConfiFile($argv);
$server = $config['irc']['server'];
$port = $config['irc']['port'];
$password = $config['bot']['password'];
$nickname = $config['bot']['nickname'];
$ident = VERSION;
$gecos = 'Bot Kato Marika ' . VERSION;
$channel = $config['irc']['channel'];

$bentenmaru=new KatoMarika($server,$port,$password,$nickname,$ident,$gecos,$channel);
$bentenmaru->connection();
function parseConfiFile($argv){
  if(file_exists('config.ini')){
    $config = parse_ini_file('config.ini',true);
    if(empty($config['irc']['server'])){
      $config['irc']['server'] = 'chat.freenode.net';
    }
    if(empty($config['irc']['port'])){
      $config['irc']['port'] = 6667;
    }
    if(empty($config['irc']['channel'])){
      $config['irc']['channel'] = '#pathey';
    }
    if(empty($config['bot']['nickname'])){
      $config['bot']['nickname'] = 'KatoMarika';
    }
    if(empty($config['bot']['password'])){
      $config['bot']['password'] = $argv[1];
    }
    return $config;
  }else{
    $defaultConfig = "[irc]
server =chat.freenode.net
port=6667
channel=#bentenmaru

[bot]
nickname=KatoMarika
;password=";
    file_put_contents('config.ini',$defaultConfig);
    file_put_contents('php://stderr', "Fichier de configuration créé, éditez-le puis relancez le bot.\n");
    exit(1);
  }
}

class KatoMarika{
  //set les variables utilisées dans certaines commandes irc
  private $opList=array();
  private $autoKickOn=false;
  private $kickDelaiMax=10;
  private $kickNombreMessagesMax=6;
  private $prefixe='.';
  private $connexionHisto=array();
  private $logUserMax=10;

  //tableau de ce que dis le bot lorsqu'un user JOIN
  private $tabBonjour=array("Bonjour","Salutations","Coucou","Yo","Salut","Hey");
  //set le tableau des phrases que va dire le bot lorsqu'il est mentionné
  private $tabPhrases=array("<3","Hey !");

  //set la connection au réseau
  private $socket;
  private $error;

  private $password;
  private $nickname;
  private $ident;
  private $gecos;
  private $channel;
  public function __construct($server,$port,$password,$nickname,$ident,$gecos,$channel){
    $this->socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
    $this->error = socket_connect( $this->socket, $server, $port );
    $this->password = $password;
    $this->nickname = $nickname;
    $this->ident=$ident;
    $this->gecos=$gecos;
    $this->channel=$channel;

  }
  //gestion des erreurs
  public function connection(){
    if ($this->socket==false){
      $errorCode = socket_last_error();
      $errorString = socket_strerror( $errorCode );
      die( "Error $errorCode: errorString\n");
    }
    echo "Nickname : ".$this->nickname;
    //info de connection
    socket_write($this->socket, "PASS $this->password\r\n");
    socket_write($this->socket, "NICK $this->nickname\r\n");
    socket_write($this->socket, "USER $this->ident * 8 :$this->gecos\r\n");

    //permet de maintenir la connection
    while (is_resource($this->socket)){
      //prends les données du socket
      $data=trim(socket_read($this->socket, 1024, PHP_NORMAL_READ));
      echo $data."\n";

      //sépares les données dans un tableau
      $d=explode(' ', $data);

      //décale le tableau pour evider des erreurs de placement:q
      $d = array_pad($d, 10, '' );

      //gestion du ping, y répond par pong
      //PING : rajaniemi.freenode.net
      if ($d[0]==='PING'){
        socket_write($this->socket, 'PONG '.$d[1]."\r\n");
      }

      //rejoins le channel $channel

      if ($d[1]==='376'||$d[1]==='422'){
        socket_write($this->socket, 'JOIN '.$this->channel."\r\n");
        socket_write($this->socket, 'MODE '.$this->channel." +o \r\n");
      }

      //fait un message random quand le bot est mensionné
      if (!empty(preg_grep('*'.$this->nickname.'*i' ,$d))&&$d[1]=='PRIVMSG'&&$d[2]==$this->channel){
        //affiche le préfixe si il est demandé
        if (!empty(preg_grep('*pr[ée]fix*ui' ,$d))&&$d[1]=='PRIVMSG'){
          socket_write($this->socket, 'PRIVMSG '.$d[2]." :Le préfixe actuel est ".$this->prefixe."\r\n");
        }else{
          if(!empty($plouf=preg_grep('/([1-9]|10)d([1-9][0-9]*)/',$d))&&$d[1]=='PRIVMSG'){
            $plouf=array_values($plouf);
            $des=explode("d", $plouf[0]);
            $this->roll($des[0], $des[1]);
          }else{
            $phrase=$this->tabPhrases[array_rand($this->tabPhrases)];
            socket_write($this->socket, 'PRIVMSG '.$d[2]." :".$phrase."\r\n");
          }
        }
      }

      if (!empty(preg_grep('*Bot_?Nazi*i' ,$d))&&$d[1]=='PRIVMSG'){
        $user=substr($d[0], 1);
        $tab=explode("!",$user);
        $user=$tab[0];
        if (!in_array($user, $this->opList)){
          socket_write($this->socket, 'KICK '.$d[2].' '.$user." :Je ne suis pas Nazi :'(\r\n");
        }
      }

      //stoque le fait qu'un user passe op
      // [0]                      [1]     [2]           [3]
      //d :nickname!ident@hostname PRIVMSG #botter-test :.test
      if ($d[3]=='+o'&&$d[1]=='MODE'){
        if (!in_array($d[4], $this->opList)){
          array_push($this->opList, $d[4]);
        }
      }

      //dit bonjour quand un user JOIN
      if ($d[1]=='JOIN'){
        $user=substr($d[0], 1);
        $tab=explode("!",$user);
        $user=$tab[0];
        $Bonjour=$this->tabBonjour[array_rand($this->tabBonjour)];
        if($user==$this->nickname){
          socket_write($this->socket, 'PRIVMSG '.$d[2]." :".$Bonjour." tout le monde !\r\n");
        }else{
          socket_write($this->socket, 'PRIVMSG '.$d[2]." :".$Bonjour.' '.$user." !\r\n");
          $this->connexionHistoAjout($user);
        }

      }

      //enlève l'op comme op si il se déconnecte
      if($d[1]=='PART'||$d[1]=='QUIT'){
        $leaver=substr($d[0], 1);
        $tab=explode("!",$leaver);
        $leaver=$tab[0];
        if (in_array($leaver, $this->opList)){
          $pos=array_search($leaver, $this->opList);
          unset($this->opList[$pos]);
          $this->opList=array_values($this->opList);
        }
      }elseif ($d[1]=='KICK'&&(in_array($d[3], $this->opList))) {
        $pos=array_search($d[3], $this->opList);
        unset($this->opList[$pos]);
        $this->opList=array_values($this->opList);
      }

      //gère le changement de nick d'un op
      if($d[1]=='NICK'){
        $nouvPseudo=substr($d[2], 1);
        $leaver=substr($d[0], 1);
        $tab=explode("!",$leaver);
        $leaver=$tab[0];
        if (in_array($leaver, $this->opList)){
          $pos=array_search($leaver, $this->opList);
          unset($this->opList[$pos]);
          $this->opList=array_values($this->opList);
          array_push($this->opList, $nouvPseudo);
        }
      }

      //check si une commande est effectuée
      switch($d[3]){
        case ':'.$this->prefixe.'help':
          $this->help($d);
          break;

        case ':'.$this->prefixe.'test':
          $this->test($d);
          break;

        case ':'.$this->prefixe.'autokick':
          $this->ableAutokick($d);
          break;

          case ':'.$this->prefixe.'op':
          $this->list_addOP($d);
          break;

        case ':'.$this->prefixe.'deop':
          $this->deop($d);
          break;

        case ':'.$this->prefixe.'unop':
          $this->unop($d);
          break;

        case ':'.$this->prefixe.'prefixe':
          $this->changePrefixe($d);
          break;

        case ':'.$this->prefixe.'voice':
          $this->voice($d);
          break;

        case ':'.$this->prefixe.'unvoice':
          $this->unvoice($d);
          break;

        case ':'.$this->prefixe.'trivia':
          $this->ableTrivia($d);
          break;

        case ':'.$this->prefixe.'say':
          $this->say($d);
          break;

        case ':'.$this->prefixe.'radio':
          $this->radio($d);
          break;

        case ':'.$this->prefixe.'roll':
          $des=explode("d", $d[4]);
          $this->roll($des[0], $des[1]);
          break;

        case ':'.$this->prefixe.'log':
          $this->appelConnexionHisto($d);
          break;

        case ':'.$this->prefixe.'note':
          //effectue une fonction différente en fonction du parametre de .note
          switch($d[4]){
            case 'ajout':
              $db=$this->bdd();
              $this->ajoutNote($d, $db);
              $db->close();//ferme la bdd
              break;

            case 'select':
              $db=$this->bdd();
              $this->selectNote($d, $db);
              $db->close();//ferme la bdd
              break;

            case 'liste':
              $db=$this->bdd();
              $this->listNote($d, $db);
              $db->close();//ferme la bdd
              break;

            case 'suppr':
              $db=$this->bdd();
              $this->deleteNote($d, $db);
              $db->close();//ferme la bdd
              break;

            default:
              socket_write($this->socket, 'PRIVMSG '.$this->channel.' :'.$this->prefixe.'note ajout titre note : ajoute une note'."\r\n");
              socket_write($this->socket, 'PRIVMSG '.$this->channel.' :'.$this->prefixe.'note select titre : afficher la note'."\r\n");
              socket_write($this->socket, 'PRIVMSG '.$this->channel.' :'.$this->prefixe.'note liste : liste les notes existantes'."\r\n");
              socket_write($this->socket, 'PRIVMSG '.$this->channel.' :'.$this->prefixe.'note suppr titre : supprime la note titre'."\r\n");
              break;
          }
          break;
      }


      //ajoute l'user à un tableau avec son pseudo en tant que key et le timestamp de son message en valeur
      //le kick si il a trop parlé pendant les 4 dernières secondes
      if ($d[1]=='PRIVMSG'&&$d[0]!=':ChanServ!ChanServ@services.'&&$this->autoKickOn){
    	  $user=substr($d[0], 1);
        $tab=explode("!",$user);
        $user=$tab[0];
      	if (!in_array($user, $this->opList)){
      		$timeStamp=time();
      		if (!isset($this->logFlood[$user])||!array_key_exists($user,$this->logFlood)){
      			$this->logFlood[$user]=array($timeStamp);
      		}else{
      			array_push($this->logFlood[$user], $timeStamp);
      			if(count($this->logFlood[$user])>$this->kickNombreMessagesMax){
      				$firstTimeStamp=array_shift($this->logFlood[$user]);
      				echo $firstTimeStamp;
      				if(($timeStamp-$firstTimeStamp)<=$this->kickDelaiMax){
      					socket_write($this->socket, 'KICK '.$d[2].' '.$user." :Tu me fais mal à la tête !\r\n");
      				}
      			}
      		}
      	}
      }
    }
  }

  //affiche la liste liste des commandes
  function help($d){
    $demandeur=substr($d[0], 1);
    $tab=explode("!",$demandeur);
    $demandeur=$tab[0];
    if (!is_null($d[4])&&($d[4]!='')) {
      switch ($d[4]) {
        case 'test':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."test : Je renvois l'identifiant de l'user ainsi que son message.\r\n");
          break;

        case 'autokick':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."autokick : J'active ou désactive l'autokick\r\n");
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."autokick nbSec nbMess: Je modifie le nombre de messages autorisé dans l'intervale de secondes précisé\r\n");
          socket_write($this->socket, 'NOTICE '.$demandeur." :  Ces commandes sont uniquement effectuables par un op.\r\n");
          break;

        case 'op':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."op : J'affiche les utilisateurs que je considère comme OP (si vous êtes OP et que je ne vous pas considère comme tel, faites /op votrePseudo).\r\n");
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."op pseudo : J'ajoute pseudo en tant que op, seulement quelqu'un déjà op peut ajouter un op.\r\n");
          break;

        case 'deop':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."deop pseudo : J'enlève pseudo en tant que op\r\n");
          socket_write($this->socket, 'NOTICE '.$demandeur." :  Cette commande est uniquement effectuable par un op.\r\n");
          break;

        case 'unop':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."unop : Je m'enlève le statut d'OP, mais pourquoi me feriez vous faire ça ? :( Et en plus, seulement un op peut le faire !\r\n");
          break;

        case 'voice':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."voice user : J'autorise un user à parler sur un channel modéré, seulement un op peut effectuer cette commande.\r\n");
          break;

        case 'unvoice':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."unvoice user : J'enlève les droits d'un user de parler sur un channel modéré (ça c'est si vous n'êtes pas gentils), seulement un op peut effectuer cette commande.\r\n");
          break;

        case 'prefixe':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."prefixe nouveauPrefixe : Je change le préfixe d'appel de mes commandes, le préfixe actuel est ".$this->prefixe."\r\n");
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  Seulement un op peut effectuer cette commande.'."\r\n");
          break;

        case 'trivia':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."trivia : J'active ou désactive le trivia, seulement un op peut le faire.\r\n");
          break;

        case 'say':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."say truc à dire : Je dis ce qu'on me dis de dire.\r\n");
          break;

        case 'autokick':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."autokick : J'active ou désactive l'autokick\r\n");
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."autokick delai messsage: J'active l'autokick et change le nombre de messages autorisés en delai secondes\r\n");
          socket_write($this->socket, 'NOTICE '.$demandeur." :  Ces commandes sont uniquement effectuables par un op.\r\n");
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."autokick help : Je dis si l'autokick est activé ou non et affiche le nombre de messages maximum pendant quelle durée.\r\n");
          break;

        case 'radio':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."radio : J'annonce les musiques en cours de diffusion sur https://j-pop.moe .\r\n");
          break;

        case 'roll':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."roll ndf: Je lance n dés f, avec un maximum de 10 dés.\r\n");
          break;

        case 'log':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."log : J'affiche les '.$this->logUserMax.' derniers user connectés\r\n");
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe."log nb: Je change le nombre d'user max dans le log à nb, seul un op peut faire cette commande.\r\n");
          break;

        case 'note':
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe.'note ajout titre note : j\'ajoute une note'."\r\n");
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe.'note select titre : j\'affiche la note titre'."\r\n");
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe.'note liste : je liste les notes existantes'."\r\n");
          socket_write($this->socket, 'NOTICE '.$demandeur.' :  '.$this->prefixe.'note suppr titre : je supprime la note titre (seul un op ou le créateur de la note peut le faire)'."\r\n");
          break;

        default:
          socket_write($this->socket, 'NOTICE '.$demandeur." :  Je ne connais pas cette commande :(\r\n");
          break;

      }
    }else{
      socket_write($this->socket, 'NOTICE '.$demandeur.' :Je suis '.$this->nickname.', un bot pour gérer '.$this->channel.", j'espère que tu seras gentil avec moi <3\r\n");
      socket_write($this->socket, 'NOTICE '.$demandeur." :Je connais les commandes suivantes :\r\n");
      socket_write($this->socket, 'NOTICE '.$demandeur." :  test, autokick, op, deop, unop, voice, unvoice, prefixe, trivia, say, autokick, radio, roll, log et note\r\n");
      socket_write($this->socket, 'NOTICE '.$demandeur." :  Fais .help nomDeCommande pour en savoir plus sur une commande\r\n");
    }
  }

  function test($d){
    socket_write($this->socket, 'PRIVMSG '.$d[2]." :$d[0] : $d[4]\r\n");
  }

  function ableAutokick($d){
    $demandeur=substr($d[0], 1);
    $tab=explode("!",$demandeur);
    $demandeur=$tab[0];
    if (!is_null($d[4])&&($d[4]=='help')) {
      if($this->autoKickOn){
        socket_write($this->socket, 'PRIVMSG '.$d[2]." :L'autokick est activé.\r\n");
        socket_write($this->socket, 'PRIVMSG '.$d[2].' :Le nombre de messages maximum en '.$this->kickDelaiMax.'s est de '.$this->kickNombreMessagesMax.".\r\n");
      }else{
        socket_write($this->socket, 'PRIVMSG '.$d[2]." :L'autokick est désactivé.\r\n");
      }
    }else{
      if(in_array($demandeur, $this->opList)){
        if(!is_null($d[4])&&!is_null($d[5])&&($d[4]!='')&&($d[5]!='')){
          if(!$this->autoKickOn){
            $this->autoKickOn=true;
            socket_write($this->socket, 'PRIVMSG '.$d[2]." :J'active l'auto kick, si vous floodez trop, je vous vire <3\r\n");
          }
          $this->kickDelaiMax=intval($d[4]);
          $this->kickNombreMessagesMax=intval($d[5]);
          socket_write($this->socket, 'PRIVMSG '.$d[2].' :Le nouveau nombre de messages maximum en '.$this->kickDelaiMax.'s est de '.$this->kickNombreMessagesMax.".\r\n");
        }else{
          if($this->autoKickOn){
            socket_write($this->socket, 'PRIVMSG '.$d[2]." :Je désactive l'auto kick, mais évitez quand même de flood ;)\r\n");
            $this->autoKickOn=false;
          }else{
            socket_write($this->socket, 'PRIVMSG '.$d[2]." :J'active l'auto kick, si vous floodez trop, je vous vire <3\r\n");
            if(!is_null($d[4])&&!is_null($d[5])&&($d[4]!='')&&($d[5]!='')){
              $this->kickDelaiMax=intval($d[4]);
              $this->kickNombreMessagesMax=intval($d[5]);
            }
            socket_write($this->socket, 'PRIVMSG '.$d[2].' :Le nombre de messages maximum en '.$this->kickDelaiMax.'s est de '.$this->kickNombreMessagesMax.".\r\n");
            $this->autoKickOn=true;
          }
        }
      }else{
        socket_write($this->socket, 'PRIVMSG '.$d[2]." :Tu n'as aucune autorité sur moi !\r\n");
      }
    }
  }

  function list_addOP($d){
    $demandeur=substr($d[0], 1);
    $tab=explode("!",$demandeur);
    $demandeur=$tab[0];
    if(in_array($demandeur, $this->opList)&&(!is_null($d[4]))&&($d[4]!='')){
      if(!in_array($d[4], $this->opList)){
        array_push($this->opList, $d[4]);
        $newOPDeb=$d[4];
      }
      $i=5;
      if((!is_null($d[$i]))&&($d[$i]!='')){
        $newOP=', ';
        while((!is_null($d[$i]))&&($d[$i]!='')){
          if(!in_array($d[$i], $this->opList)){
              array_push($this->opList, $d[$i]);
              $newOP=$newOP.$d[$i].', ';

          }
          $i++;
        }
        $newOP=substr($newOP, 0, -2);
        $newOP=$newOPDeb.$newOP;
        $lastNewOP = substr(strrchr($newOP, ","), 1);
        $newOP = substr($newOP, 0, strrpos($newOP, ','));
        $newOP = $newOP.' et'.$lastNewOP;
      }else{
        $newOP=$newOPDeb;
      }
      socket_write($this->socket, 'PRIVMSG '.$d[2].' :Je considère maintenant '.$newOP." comme op.\r\n");
    }else{
      if(count($this->opList)>1){
        $opCo='Je considère les utilisateurs suivant comme op : ';
        foreach($this->opList as $key => $valeur){
          if ($key!=0){
            $opCo=$opCo.', '.$valeur;
          }else{
            $opCo=$opCo.$valeur;
          }
          if ($valeur==$this->nickname){
            $opCo=$opCo." (c'est moi hihi <3)";
          }
        }
        $lastOP = substr(strrchr($opCo, ","), 1);
        $opCo = substr($opCo, 0, strrpos($opCo, ','));
        $opCo = $opCo.' et'.$lastOP;
      }else if((count($this->opList)==1)){
        $opCo="Je considère uniquement ".$this->opList[0]." comme op";
      }else{
        $opCo="Je ne considère personne comme op";
      }
      socket_write($this->socket, 'NOTICE '.$demandeur.' :'.$opCo."\r\n");
    }
  }

  function deop($d){
    $demandeur=substr($d[0], 1);
    $tab=explode("!",$demandeur);
    $demandeur=$tab[0];
    if(in_array($demandeur, $this->opList)){
      if((!is_null($d[4]))&&($d[4]!='')&&(in_array($d[4], $this->opList))){
        $pos=array_search($d[4], $this->opList);
        unset($this->opList[$pos]);
        $this->opList=array_values($this->opList);
        socket_write($this->socket, 'PRIVMSG '.$d[2].' :Je ne considère plus '.$d[4]." comme op\r\n");
      }else{
        socket_write($this->socket, 'PRIVMSG '.$d[2]." :Précise moi quelqu'un à enlever de ma liste des op.\r\n");
      }
    }else{
      socket_write($this->socket, 'PRIVMSG '.$d[2]." :Ne me donnes pas d'ordres !\r\n");
    }
  }

  function unop($d){
    $demandeur=substr($d[0], 1);
    $tab=explode("!",$demandeur);
    $demandeur=$tab[0];
    if(in_array($demandeur, $this->opList)){
      socket_write($this->socket, 'MODE '.$d[2].' -o '.$this->nickname."\r\n");
    }else{
      socket_write($this->socket, 'PRIVMSG '.$d[2]." :Ne touche pas à mes permissions, mécréant !\r\n");
    }
  }

  function changePrefixe($d){
    $demandeur=substr($d[0], 1);
    $tab=explode("!",$demandeur);
    $demandeur=$tab[0];
    if(in_array($demandeur, $this->opList)){
      if((!is_null($d[4]))&&($d[4]!='')){
        $this->prefixe=$d[4];
        socket_write($this->socket, 'PRIVMSG '.$d[2]." :Le nouveau préfixe de mes commandes est ".$this->prefixe."\r\n");
      }else{
        socket_write($this->socket, 'PRIVMSG '.$d[2]." :Le préfixe de mes commandes est ".$this->prefixe."\r\n");
      }
    }else{
      socket_write($this->socket, 'PRIVMSG '.$d[2]." :Ne touche pas à mes commandes, mécréant !\r\n");
    }
  }

  function voice($d){
    $demandeur=substr($d[0], 1);
    $tab=explode("!",$demandeur);
    $demandeur=$tab[0];
    if(in_array($demandeur, $this->opList)){
      socket_write($this->socket, "PRIVMSG ChanServ :flags $d[2] $d[4] +vV\r\n");
      socket_write($this->socket, 'MODE '.$d[2].' v '.$d[4]."\r\n");
      socket_write($this->socket, 'PRIVMSG '.$d[2].' :Tu peux maintenant parler '.$d[4]." :)\r\n");
      socket_write($this->socket, 'PRIVMSG '.$d[4]." :Si tu n'as pas enregistré ton compte, fait le avec la commande '/msg NickServ REGISTER password nom@domain.com' puis vérifie l'email, sinon ChanServ va venir te retirer tes droits.\r\n");
    }else{
      socket_write($this->socket, 'PRIVMSG '.$d[2].' :Désolé '.$demandeur.", mais tu n'es pas OP, je n'ai aucune raison de t'obéir.\r\n");
    }
  }

  function unvoice($d){
    $demandeur=substr($d[0], 1);
    $tab=explode("!",$demandeur);
    $demandeur=$tab[0];
    if(in_array($demandeur, $this->opList)){
      socket_write($this->socket, 'PRIVMSG '.$d[2].' :Alors, comme ça on embête tout le monde '.$d[4]." ?\r\n");
      socket_write($this->socket, "PRIVMSG ChanServ :flags $d[2] $d[4] -vV\r\n");
      socket_write($this->socket, 'MODE '.$d[2].' -v '.$d[4]."\r\n");
      socket_write($this->socket, 'PRIVMSG '.$d[2]." :Estime toi heureux que je ne te coupe que la langue ! Si ça ne tenait qu'à moi, tu serais déjà mort <3\r\n");
    }else{
      socket_write($this->socket, 'PRIVMSG '.$d[2].' :Désolé '.$demandeur.", mais tu n'es pas OP, je n'obéis qu'à eux.\r\n");
    }
  }

  function ableTrivia($d){
    $demandeur=substr($d[0], 1);
    $tab=explode("!",$demandeur);
    $demandeur=$tab[0];
    if(in_array($demandeur, $this->opList)){
      if(!$this->trivia){
        $this->autoKickOn = false;
        socket_write($this->socket, 'PRIVMSG '.$d[2]." :?start\r\n");
        socket_write($this->socket, 'PRIVMSG '.$d[2]." :Je désactive l'auto kick pour toi Pathey_triviabot <3 \r\n");
        $this->trivia=true;
      }else{
        socket_write($this->socket, 'PRIVMSG '.$d[2]." :?stop\r\n");
        $this->autoKickOn = true;
        socket_write($this->socket, 'PRIVMSG '.$d[2]." :Le trivia est finit, je réactive l'autokick. \r\n");
        $this->trivia=false;
      }
    }else{
      socket_write($this->socket, 'PRIVMSG '.$d[2]." :Ce n'est pas à toi de décider quand lancer le trivia !\r\n");
    }
  }

  function say($d){
    $demandeur=substr($d[0], 1);
    $tab=explode("!",$demandeur);
    $demandeur=$tab[0];
    if(in_array($demandeur, $this->opList)){
      $taille = count($d);
      $dire='';
      for ($i=4; $i < $taille; $i++) {
        $dire=$dire.$d[$i].' ';
      }
      socket_write($this->socket, 'PRIVMSG '.$d[2].' :'.$dire."\r\n");
    }else{
      socket_write($this->socket, 'PRIVMSG '.$d[2]." :Vaurien ! Mécréant !! Ne me fait pas dire des trucs étranges $demandeur !\r\n");
    }
  }

  function radio($d){
    if ($d[4]=="skip"){
      $telnet=fsockopen("127.0.0.1", 1234);
      if($telnet){
        fputs($telnet,"radio(dot)mp3.skip \r\n");
        socket_write($this->socket, 'PRIVMSG '.$d[2].' :Je viens de changer de chanson :) '."\r\n");
      }else{
        socket_write($this->socket, 'PRIVMSG '.$d[2]." :La radio n'est pas lancée "."\r\n");
      }
      fclose($telnet);
    }else{
      $radiotag=file_get_contents("/var/www/j-pop/music-names.txt");
      $radiotag=explode("||",$radiotag);
      $radio=$radiotag[0];
      $miku=$radiotag[1];
      socket_write($this->socket, 'PRIVMSG '.$d[2].' :Actuellement sur https://j-pop.moe : '."\r\n");
      socket_write($this->socket, 'PRIVMSG '.$d[2].' : J-pop : '.$radio."\r\n");
      socket_write($this->socket, 'PRIVMSG '.$d[2].' : Miku : '.$miku."\r\n");
    }
  }

  function roll($des, $faces){
    if($faces==0||$des==0){
      socket_write($this->socket, 'PRIVMSG '.$this->channel.' :Donnes moi des dés valides !'."\r\n");
    }else{
      if ($des<=10){
        if ($des==1){
          $plouf=mt_rand(1, $faces);
          socket_write($this->socket, 'PRIVMSG '.$this->channel.' :Je lance '.$des.'d'.$faces.' !'."\r\n");
          socket_write($this->socket, 'PRIVMSG '.$this->channel.' : Résultat : '.$plouf."\r\n");
        }else{
          $total=0;
          $listeDes='';
          for ($i=0 ; $i < $des ; $i++) {
            $plouf=mt_rand(1, $faces);
            $total+=$plouf;
            if($i<$des-1){
              $listeDes=$listeDes.$plouf.', ';
            }else{
              $listeDes=$listeDes.$plouf;
            }
          }
          socket_write($this->socket, 'PRIVMSG '.$this->channel.' :Je lance '.$des.'d'.$faces.' !'."\r\n");
          socket_write($this->socket, 'PRIVMSG '.$this->channel.' : Résultats : '.$listeDes."\r\n");
          socket_write($this->socket, 'PRIVMSG '.$this->channel.' : Total : '.$total."\r\n");
        }

      }else{
        socket_write($this->socket, 'PRIVMSG '.$this->channel.' :Je ne peux pas lancer autant de dés :('."\r\n");
      }
    }
  }

  function connexionHistoAjout($user){
    $timeStamp=time();
    if (!isset($this->connexionHisto[$user])||!array_key_exists($user,$this->connexionHisto)){
      $this->connexionHisto[$user]=array($timeStamp);
    }else{
      unset($this->connexionHisto[$user]);
      $this->connexionHisto[$user]=array($timeStamp);

    }
    if(count($this->connexionHisto)>$this->logUserMax){
      array_shift($this->connexionHisto);
    }
  }

  function appelConnexionHisto($d){
    $liste='';
    if((!is_null($d[4]))&&($d[4]!='')){
      $demandeur=substr($d[0], 1);
      $tab=explode("!",$demandeur);
      $demandeur=$tab[0];
      if(in_array($demandeur, $this->opList)){
        $this->logUserMax=$d[4];
        socket_write($this->socket, 'PRIVMSG '.$d[2]." :Le nombre d'user dans le log est maintenant de ".$this->logUserMax."\r\n");
      }else{
        socket_write($this->socket, 'PRIVMSG '.$d[2]." :N'essaye pas de faire quoi que ce soit !"."\r\n");
      }
    }else{
      socket_write($this->socket, 'PRIVMSG '.$this->channel.' :Voici les '.$this->logUserMax.' dernières personnes connectées :'."\r\n");
      foreach ($this->connexionHisto as $key => $value) {
        $time=time()-$value[0];
        $dtF = new \DateTime('@0');
        $dtT = new \DateTime("@$time");
        $time= $dtF->diff($dtT)->format('%a j, %h h, %i min et %s s');
        socket_write($this->socket, 'PRIVMSG '.$this->channel.' : '.$key.' : '.$time." \r\n");
      }
    }
  }

  function bdd(){
    //ouvre la bdd
    $db = new sqlite3('./BDD/sqlite3.db');

    if(!$db){
      echo $db->lastErrorMsg();
    }

    //créé la table si elle n'existe pas déjà
    $sql =<<<EOF
        CREATE TABLE IF NOT EXISTS notes
        (ID 				INTEGER PRIMARY KEY     NOT NULL,
        PSEUDO           	TEXT   					NOT NULL,
        TITRE           	TEXT            NOT NULL,
        NOTE          		TEXT    				NOT NULL,
        DATE            	TEXT    				NOT NULL);
EOF;
    $db->exec($sql);
    return $db;
  }

  function ajoutNote($d, $db){
    $demandeur=substr($d[0], 1);
    $tab=explode("!",$demandeur);
    $demandeur=$tab[0];
    if((!is_null($d[5]))&&($d[5]!='')&&(!is_null($d[4]))&&($d[4]!='')){
      $vali=$db->prepare("SELECT count(1) FROM notes where TITRE = (:TITRE)");
    	$vali->bindValue(':TITRE', $d[5]);
    	$ret = $db->exec($sql);
    	$res=$vali->execute();
    	$row = $res->fetchArray();
    	if(!$res){
    		echo $db->lastErrorMsg();
    	}
    	if($row[0]>0){
    		socket_write($this->socket, 'PRIVMSG '.$this->channel.' :Désolé '.$demandeur.', mais une note portant ce titre existe déjà !'." \r\n");
    	}else{
        $i=6;
        $note="";
        while((!is_null($d[$i]))&&($d[$i]!='')){
          $note=$note.$d[$i]." ";
          $i++;
        }
        $ajout = $db->prepare("INSERT INTO notes (PSEUDO,TITRE,NOTE,DATE) VALUES (:PSEUDO,:TITRE,:NOTE,:DATE)");
    		$ajout->bindValue(':PSEUDO', $demandeur);
        $ajout->bindValue(':TITRE', $d[5]);
    		$ajout->bindValue(':NOTE', $note);
    		$ajout->bindValue(':DATE', date("d/m/y"));
        $ret=$ajout->execute();//execute la requête
    		if(!$ret){
    			echo $db->lastErrorMsg();
    		}
    		echo "Note ajoutée";
        socket_write($this->socket, 'PRIVMSG '.$this->channel.' :J\'ai bien enregistré ta note '.$demandeur.' :)'." \r\n");
      }
    }else{
      socket_write($this->socket, 'PRIVMSG '.$this->channel.' :Ajoute un contenu à ta note '.$demandeur.' !'." \r\n");
    }
  }

  function selectNote($d, $db){
    if((!is_null($d[5]))&&($d[5]!='')){
      $note = $db->prepare("SELECT * from notes where TITRE = (:TITRE)");
  		$note->bindValue(':TITRE', $d[5]);
  		$res = $note->execute();
  		$row = $res->fetchArray();
      if(count($row)<2){
        socket_write($this->socket, 'PRIVMSG '.$this->channel.' :Désolé, cette note n\'existe pas encore :('." \r\n");
      }else{
        socket_write($this->socket, 'PRIVMSG '.$this->channel.' :'.$row[2].' par '.$row[1].' le '.$row[4]." : \r\n");
        socket_write($this->socket, 'PRIVMSG '.$this->channel.' : '.$row[3]." \r\n");
      }
    }else{
      socket_write($this->socket, 'PRIVMSG '.$this->channel.' :Précise moi une note à afficher. .note liste pour savoir quelles notes sont disponibles'." \r\n");
    }
  }

  function listNote($d, $db){
    $note = $db->prepare("SELECT TITRE from notes");
    $res = $note->execute();
    $row = $res->fetchArray();
    if(count($row)<1){
      socket_write($this->socket, 'PRIVMSG '.$this->channel.' :Désolé, je ne connais encore aucune note, .note ajout titre note pour en ajouter :)'." \r\n");
    }else{
      $notesExistante=$row[0].', ';
      while($row=$res->fetchArray()){
        $notesExistante=$notesExistante.$row[0].', ';
      }
      $notesExistante=rtrim($notesExistante,', ');
      socket_write($this->socket, 'PRIVMSG '.$this->channel.' :Je connais les notes suivantes :'." \r\n");
      socket_write($this->socket, 'PRIVMSG '.$this->channel.' : '.$notesExistante." \r\n");
      socket_write($this->socket, 'PRIVMSG '.$this->channel.' :.note select titre pour afficher la note souhaitée '." \r\n");
    }
  }

  function deleteNote($d, $db){
    $demandeur=substr($d[0], 1);
    $tab=explode("!",$demandeur);
    $demandeur=$tab[0];
    if((!is_null($d[5]))&&($d[5]!='')){
      $note = $db->prepare("SELECT * from notes where TITRE = (:TITRE)");
      $note->bindValue(':TITRE', $d[5]);
      $res = $note->execute();
      $row = $res->fetchArray();
      if(count($row)<2){
        socket_write($this->socket, 'PRIVMSG '.$this->channel.' :Désolé, cette note n\'existe pas.'." \r\n");
      }else{
        if(in_array($demandeur, $this->opList)||$demandeur==$row[1]){
          $supp = $db->prepare("DELETE from notes where TITRE = (:TITRE)");
          $supp->bindValue(':TITRE', $row[2]);
          $supp->execute();
          socket_write($this->socket, 'PRIVMSG '.$this->channel.' :J\'ai bien supprimé la note '.$row[2].". \r\n");
        }else{
          socket_write($this->socket, 'PRIVMSG '.$this->channel.' :Uniquement '.$row[1].' ou un OP peut supprimer cette note.'." \r\n");
        }
      }
    }else{
      socket_write($this->socket, 'PRIVMSG '.$this->channel.' :Précise une note à supprimer.'." \r\n");
    }
  }
}
?>
