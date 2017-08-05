<?php

namespace alkurn\websocket;


class Socket extends Base
{
    public $host;
    public $port;

    public $clients;
    public $verboseMode = false;

    public function init()
    {
        $host = $this->host;
        $port = $this->port;

        // start the server
        $ws = new parent();
        $ws->bind('message', 'wsOnMessage');
        $ws->bind('open', 'wsOnOpen');
        $ws->bind('close', 'wsOnClose');
        // for other computers to connect, you will probably need to change this to your LAN IP or external IP,
        // alternatively use: gethostbyaddr(gethostbyname($_SERVER['SERVER_NAME']))
        $ws->wsStartServer($this->host, $this->port);
    }


// when a client sends data to the server
    function wsOnMessage($clientID, $message, $messageLength, $binary)
    {

        $ip = long2ip($this->wsClients[$clientID][6]);

        // check if message length is 0
        if ($messageLength == 0) {
            $this->wsClose($clientID);
            return;
        }

        //Send the message to everyone but the person who said it
        foreach ($this->wsClients as $id => $client)
            if ($id != $clientID)
                $this->wsSend($id, "Visitor $clientID ($ip) said \"$message\"");
    }

// when a client connects
    function wsOnOpen($clientID)
    {

        $ip = long2ip($this->wsClients[$clientID][6]);
        $this->log("$ip ($clientID) has connected.");

        //Send a join notice to everyone but the person who joined
        foreach ($this->wsClients as $id => $client)
            if ($id != $clientID)
                $this->wsSend($id, "Visitor $clientID ($ip) has joined the room.");
    }

// when a client closes or lost connection
    function wsOnClose($clientID, $status)
    {

        $ip = long2ip($this->wsClients[$clientID][6]);
        $this->log("$ip ($clientID) has disconnected.");

        //Send a user left notice to everyone in the room
        foreach ($this->wsClients as $id => $client)
            $this->wsSend($id, "Visitor $clientID ($ip) has left the room.");
    }

}