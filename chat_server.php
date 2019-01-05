<?php
	$count = 1;
	$pos = 0;
	$customers_waiting = 0;
	$host = 'localhost'; //host
	$port = '5051'; //port
	$null = NULL; //null var
	$manager_connected = false;
	$managers = 0;
	$current_chat_customer = 0;
	$chat_in_progress = false;
	
	//Create TCP/IP sream socket
	$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
	//reuseable port
	socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
	//bind socket to specified host
	socket_bind($socket, 0, $port);
	//listen to port
	socket_listen($socket);
	//create & add listning socket to the list
	$clients = array($socket);
	$customers = array(); //

	//start endless loop, so that our script doesn't stop
	debugToBrowserConsole ("Listening Sockets created");

	while (true) 
	{
		//manage multipal connections
		$changed = $clients;
		//returns the socket resources in $changed array
		socket_select($changed, $null, $null, 0, 10);
		$count++;
		if ($count == 5000)
		{
			debugToBrowserConsole ("Active");
			$count = 0;
		}
		
		//check for new socket
		if (in_array($socket, $changed)) 
		{
			debugToBrowserConsole ("Accepting Connection");			
			$socket_new = socket_accept($socket); //accpet new socket
			$clients[] = $socket_new; //add socket to client array
			
			$header = socket_read($socket_new, 1024); //read data sent by the socket
			perform_handshaking($header, $socket_new, $host, $port); //perform websocket handshake
			
			socket_getpeername($socket_new, $ip); //get ip address of connected socket
			//Assume all connections are customers until the first message which identifies the connection
			// as the manager connection, at which point it will be removed from the array.
			$customers[] = $socket_new; 
			$response = mask(json_encode(array('type'=>'system', 'message'=>$ip.' Waiting for Agent'))); //prepare json data
			send_message_customer($response,$socket_new); //notify this client only about new connection
	
			$pos = customers_position_in_queue($socket_new);
			$response_text = mask(json_encode(array('type'=>'system', 'message'=>'You are number '.$pos.' in the queue'))); //prepare json data
			send_message_customer($response_text,$socket_new); //send data

			if ($manager_connected)
			{
				$customers_waiting = customers_waiting();	
				$response = mask(json_encode(array('type'=>'system', 'message'=>$ip.' connected. '.$customers_waiting.' waiting'))); //prepare json data
				send_message_manager($response);
			}

			//make room for new socket
			$found_socket = array_search($socket, $changed);
			unset($changed[$found_socket]);
		}
		
		//loop through all connected sockets
		foreach ($changed as $changed_socket) 
		{	
			
			//check for any incomming data
			while(socket_recv($changed_socket, $buf, 1024, 0) >= 1)
			{
				$received_text = unmask($buf); //unmask data
				$tst_msg = json_decode($received_text); //json decode
				if ($tst_msg != null)
				{
					$user_name = $tst_msg->name; //sender name
					$user_message = $tst_msg->message; //message text
					$user_manager = $tst_msg->manager; // message manager flag
					$user_color = $tst_msg->color; //color
					
					if ($user_manager)
					{
						// Establish a single manager, one pass only, reset when manager disconnects.
						if (!$manager_connected)
						{
							$found_socket = array_search($changed_socket, $customers);
							unset($customers[$found_socket]);
							//debugToBrowserConsole('Manager accepted next connection');
							$manager_connected = true;
							$managers = $changed_socket;
							$customers_waiting = customers_waiting();	
							$response = mask(json_encode(array('type'=>'system', 'message'=>'On initial connection '.$customers_waiting.' waiting'))); //prepare json data
							send_message_manager($response);
						}
						if (!$chat_in_progress)
						{
							$customers_waiting = customers_connected();	
							// Talk to first in queue
							if ($customers_waiting > 0)
							{
								$current_chat_customer = customers_connected_first();
								$chat_in_progress = true;
								$response = mask(json_encode(array('type'=>'system', 'message'=>' Now talking to an Agent.'))); //prepare json data
								send_message_customer($response, $current_chat_customer);
								send_position_in_queue_to_all();
								$response = mask(json_encode(array('type'=>'system', 'message'=>'-------------------------------------------'))); //prepare json data
								send_message_manager($response);
								$customers_waiting = customers_waiting();	
								$response = mask(json_encode(array('type'=>'system', 'message'=>'Customer Chat started. '
																								.$customers_waiting.' still waiting'))); //prepare json data
								send_message_manager($response);
							}
						}
					}
					else
					{
					}	// if ($user_manager)
						
					//prepare data to be sent to client
					if ($chat_in_progress)
					{
						if ($changed_socket == $current_chat_customer or $changed_socket == $managers)
						{
							$response_text = mask(json_encode(array('type'=>'usermsg', 'name'=>$user_name, 'message'=>$user_message,
																	'manager'=>$user_manager, 'color'=>$user_color)));
							send_message_customer($response_text,$current_chat_customer); //send data
							send_message_manager($response_text); //copy reply to manager
						}
						else
						{
							$pos = customers_position_in_queue($changed_socket);
							$response_text = mask(json_encode(array('type'=>'system', 'message'=>'Sorry still waiting for an Agent. '.
												  'You are number '.$pos.' in the queue'))); //prepare json data
							send_message_customer($response_text,$changed_socket); //send data
						}
					}
					break 2; //exit this loop
				}  // if ($tst_msg != null)
			}  // while(socket_recv
			
			$buf = @socket_read($changed_socket, 1024, PHP_NORMAL_READ);
			if ($buf === false) 
			{ // check disconnected client
				// remove client for $clients array
				$found_socket = array_search($changed_socket, $clients);
				socket_getpeername($changed_socket, $ip);
				unset($clients[$found_socket]);
				
				if ($changed_socket == $managers)
				{
					$response = mask(json_encode(array('type'=>'system', 'message'=>'Waiting for Agent'))); //prepare json data
					$manager_connected = false;
					debugToBrowserConsole('Manager disconnect');					
					$chat_in_progress = false;
					$managers=null;
					send_message_all ($response);
					send_position_in_queue_to_all();
				}
				else
				{
					$found_socket = array_search($changed_socket, $customers);
					unset($customers[$found_socket]);
					if ($changed_socket == $current_chat_customer)
					{
						$chat_in_progress = false;
						$current_chat_customer = null;
					}
					$customers_waiting = customers_waiting();	
					$response = mask(json_encode(array('type'=>'system', 'message'=>$ip.' disconnected. '.$customers_waiting.' waiting'))); //prepare json data
					send_message_manager($response);
				}
			}  //if ($buf === false)
		} // foreach ($changed as $changed_socket) 
	}
	// close the listening socket
	socket_close($sock);

	function customers_connected()
	{
		$n = 0;
		global $customers;
		
		foreach ($customers as $soc)
		{
			$n++;
		}	
		return $n;	
	}
	function customers_waiting()
	{
		global $chat_in_progress;

		$n = customers_connected();

		if ($chat_in_progress) 
		{
			$n--;
		}

		return $n;	
	}

	function customers_connected_first()
	{
		global $customers;
		foreach ($customers as $soc)
		{
			break;
		}	
		return $soc;	
	}
	function customers_position_in_queue($cus)
	{
		global $customers;
		global $chat_in_progress;
		$n = 0;

		foreach ($customers as $soc)
		{
			$n++;
			if ($soc == $cus)
			{
				break;
			}
		}
		if ($chat_in_progress)
		{
			$n--;
		}		
		return $n;	
	}
	function send_message_all($msg)
	{
		global $customers;
		foreach($customers as $cus)
		{
			@socket_write($cus,$msg,strlen($msg));
		}
		return true;
	}
	function send_message_customer($msg, $this_client)
	{
		@socket_write($this_client,$msg,strlen($msg));
		return true;
	}
	function send_message_manager($msg)
	{
		global $managers;
		global $manager_connected;
		if ($manager_connected)
		{	
			@socket_write($managers,$msg,strlen($msg));
		}
		return true;
	}
	function send_position_in_queue_to_all()
	{
		global $customers;
		global $current_chat_customer;
		$n = 0;

		foreach ($customers as $cus)
		{
			if ($cus != $current_chat_customer)
			{
				$n++;
				$response_text = mask(json_encode(array('type'=>'system', 'message'=>'You are now number '.$n.' in the queue'))); //prepare json data
				send_message_customer($response_text,$cus); //send data
			}
		}
	}

	//Unmask incoming framed message
	function unmask($text) {
		$length = ord($text[1]) & 127;
		if($length == 126) {
			$masks = substr($text, 4, 4);
			$data = substr($text, 8);
		}
		elseif($length == 127) {
			$masks = substr($text, 10, 4);
			$data = substr($text, 14);
		}
		else {
			$masks = substr($text, 2, 4);
			$data = substr($text, 6);
		}
		$text = "";
		for ($i = 0; $i < strlen($data); ++$i) {
			$text .= $data[$i] ^ $masks[$i%4];
		}
		return $text;
	}
	//Encode message for transfer to client.
	function mask($text)
	{
		$b1 = 0x80 | (0x1 & 0x0f);
		$length = strlen($text);
		
		if($length <= 125)
			$header = pack('CC', $b1, $length);
		elseif($length > 125 && $length < 65536)
			$header = pack('CCn', $b1, 126, $length);
		elseif($length >= 65536)
			$header = pack('CCNN', $b1, 127, $length);
		return $header.$text;
	}
	//handshake new client.
	function perform_handshaking($receved_header,$client_conn, $host, $port)
	{
		$headers = array();
		$lines = preg_split("/\r\n/", $receved_header);
		foreach($lines as $line)
		{
			$line = chop($line);
			if(preg_match('/\A(\S+): (.*)\z/', $line, $matches))
			{
				$headers[$matches[1]] = $matches[2];
			}
		}
		$secKey = $headers['Sec-WebSocket-Key'];
		$secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
		//hand shaking header
		$upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
		"Upgrade: websocket\r\n" .
		"Connection: Upgrade\r\n" .
		"WebSocket-Origin: $host\r\n" .
		"WebSocket-Location: ws://$host:$port/demo/shout.php\r\n".
		"Sec-WebSocket-Accept:$secAccept\r\n\r\n";
		socket_write($client_conn,$upgrade,strlen($upgrade));
	}
	function debugToBrowserConsole ( $msg ) 
	{
		$msg = str_replace('"', "''", $msg);  # weak attempt to make sure there's not JS breakage
		//echo "<script>console.debug( \"PHP DEBUG: $msg\" );</script>";
		print $msg . "\r\n";
	}
?>