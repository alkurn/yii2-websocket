<?php

namespace alkurn\websocket;

use yii\base\Component;

class WebSocket extends Component
{
    public $host;
    public $port;

    public $socket;
    public $socket_new;
    public $clients;

    public function init()
    {
        $this->run();
    }

    public function run()
    {
        $host = $this->host ;
        $port = $this->port;

        $null = NULL; //null var
        //Create TCP/IP sream socket
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        //reuseable port
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        //bind socket to specified host
        socket_bind($this->socket, 0, $port);

        //listen to port
        socket_listen($this->socket);

        //create & add listning socket to the list
        $this->clients = array($this->socket);

        //start endless loop, so that our script doesn't stop
        while (true) {

            //manage multipal connections

            $changed = $this->clients;
            //returns the socket resources in $changed array
            socket_select($changed, $null, $null, 0, 10);

            //check for new socket
            if (in_array($this->socket, $changed)) {
                $this->socket_new = socket_accept($this->socket); //accpet new socket
                $this->clients[] = $this->socket_new; //add socket to client array

                $header = socket_read($this->socket_new, 1024); //read data sent by the socket
                $this->performHandshaking($header, $this->socket_new, $host, $port); //perform websocket handshake

                socket_getpeername($this->socket_new, $ip); //get ip address of connected socket

                $response = $this->mask(json_encode(['type' => 'system', 'message' => $ip . ' connected']));//prepare json data
                $this->sendMessage($response); //notify all users about new connection

                //make room for new socket
                $found_socket = array_search($this->socket, $changed);
                unset($changed[$found_socket]);
            }

            //loop through all connected sockets
            foreach ($changed as $changed_socket) {

                //check for any incomming data
                while (socket_recv($changed_socket, $buf, 1024, 0) >= 1) {

                    $received_text = $this->unmask($buf); //unmask data
                    $tst_msg = json_decode($received_text); //json decode

                    if ($tst_msg && count($tst_msg) > 0) {
                        $type = (isset($tst_msg->type) && !empty($tst_msg->type)) ? $tst_msg->type : 'user';
                        $info = ['code' => 'success', 'type' => $type, 'message_id' => $tst_msg->message_id, 'seq' => $tst_msg->seq, 'text' => $tst_msg->text];
                        //prepare data to be sent to client
                    } else {
                        $info = ['code' => 'failed', 'message_id' => null, 'seq' => null, 'text' => 'Error in socket'];
                    }

                    $response_text = $this->mask(json_encode($info));
                    $this->sendMessage($response_text); //send data
                    break 2; //exist this loop
                }

                $buf = @socket_read($changed_socket, 1024, PHP_NORMAL_READ);
                if ($buf === false) {

                    // check disconnected client
                    // remove client for $clients array

                    $found_socket = array_search($changed_socket, $this->clients);
                    socket_getpeername($changed_socket, $ip);
                    unset($this->clients[$found_socket]);

                    //notify all users about disconnected connection
                    $response = $this->mask(json_encode(['code' => 'failed', 'type' => 'system', 'message' => $ip . ' disconnected']));
                    $this->sendMessage($response);
                }
            }
        }
        // close the listening socket
        socket_close($this->socket);
    }

    function performHandshaking($receved_header, $client_conn, $host, $port)
    {
        $headers = array();
        $lines = preg_split("/\r\n/", $receved_header);

        foreach ($lines as $line) {
            $line = chop($line);
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $headers[$matches[1]] = $matches[2];
            }
        }

        $secKey = $headers['Sec-WebSocket-Key'];
        $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        //hand shaking header
        $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "WebSocket-Origin: $host\r\n" .
            "WebSocket-Location: ws://$host:$port/demo/shout.php\r\n" .
            "Sec-WebSocket-Accept:$secAccept\r\n\r\n";

        socket_write($client_conn, $upgrade, strlen($upgrade)) or die("Could not write outputs\n");
    }

//Unmask incoming framed message

    function mask($text)
    {
        $b1 = 0x80 | (0x1 & 0x0f);
        $length = strlen($text);

        if ($length <= 125) $header = pack('CC', $b1, $length);
        elseif ($length > 125 && $length < 65536) $header = pack('CCn', $b1, 126, $length);
        elseif ($length >= 65536) $header = pack('CCNN', $b1, 127, $length);
        return $header . $text;
    }

//Encode message for transfer to client.

    function sendMessage($msg)
    {
        if (isset($this->clients) && count($this->clients) > 0) {
            foreach ($this->clients as $changed_socket) {
                @socket_write($changed_socket, $msg, strlen($msg));
            }
        }

        return true;
    }


//handshake new client.

    function unmask($text)
    {

        $length = ord($text[1]) & 127;
        if ($length == 126) {
            $masks = substr($text, 4, 4);
            $data = substr($text, 8);
        } elseif ($length == 127) {
            $masks = substr($text, 10, 4);
            $data = substr($text, 14);
        } else {
            $masks = substr($text, 2, 4);
            $data = substr($text, 6);
        }
        $text = "";
        for ($i = 0; $i < strlen($data); ++$i) {
            $text .= $data[$i] ^ $masks[$i % 4];
        }
        return $text;
    }

}