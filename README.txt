Xampp Install
-------------
Disable Antivirus
Install Xampp as administrator

Run the code
------------
Disable Antivirus
Copy index.php and chat_server.php to C:\xampp\htdocs
From an Xampp Shell
   php -q C:\xampp\htdocs\chat_server.php
From Chrome visit
   localhost

Github
------
https://github.com/legs49/MyFirstWebChat

=======================================================================
Version 1 
4th Jan 2019
Basic chat where messages from one client are shown on all other clients.

========================================================================
Version 2
5th Jan 2019
Implemented the manager / customer protocol. The manager talks to one
customer at a time, other customers are queued with number in queue displayed,
the manager told total customers still waiting.
Only one manager is supported, a second manager will take control from the first.

Note the the Client to Server interface has changed.
The messages now include a flag that indicates if the message has come from 
the manager or customer.
This required the introduction of file Manager.php.
The actual change is small and could be applied to any other web page based
on the original.

index.php
			//prepare json data
			var msg = {
			message: mymessage,
			name: myname,
  THIS IS ADDED		manager: false,
			color : '<?php echo $colours[$user_colour]; ?>'

manager.php
The file is same as index.php but with manager: true,
			//prepare json data
			var msg = {
			message: mymessage,
			name: myname,
  THIS IS DIFFERENT	manager: true,
			color : '<?php echo $colours[$user_colour]; ?>'

