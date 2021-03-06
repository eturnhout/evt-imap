<?php
namespace Evt\Imap;

use Evt\Imap\Config;
use Evt\Util\AbstractClient;

/**
 * Evt\Imap\Cli
 *
 * Connect and interact with an imap server through a socket connection
 *
 * @author Eelke van Turnhout <eelketurnhout3@gmail.com>
 * @version 1.0
 */
class Cli extends AbstractClient
{

    /**
     * Every command to the imap server needs a unique tag
     * This is the prefix to that tag
     *
     * @var string
     */
    const TAG_PREFIX = 'AMWIJG';

    /**
     * Tag line to append to the tag prefix
     * This number should increase with every command send to the imap server
     *
     * @var int
     */
    protected $tagLine = 0;

    /**
     * The socket created while connecting.
     *
     * @var resource
     */
    protected $socket;

    /**
     * Used to handle errors.
     * An exception should be thrown when this is not null.
     *
     * @var string
     */
    protected $error;

    /**
     * Turns debug mode on to track the commands and responses.
     *
     * @var boolean
     */
    protected $debug;

    /**
     * Keeps track of the in/output when debug is set to true.
     *
     * @var string
     */
    protected $debugOutput;

    /**
     * Evt\Imap\Cli
     *
     * @param Evt\Mail\Config\ImapConfig $config Configurations needed to connect and login to an imap server
     */
    public function __construct(Config $config)
    {
        $this->setConfig($config);
        $this->debug = false;
    }

    /**
     * Connect to the server
     *
     * @throws \Exception
     */
    public function connect()
    {
        $address = ($this->config->isSsl()) ? 'ssl://' . $this->config->getHost() . ':' . $this->config->getPort() : $this->config->getHost() . ':' . $this->config->getPort();

        $this->socket = stream_socket_client($address);

        if (! is_resource($this->socket)) {
            throw new \Exception(__METHOD__ . '; There was a problem creating the socket.');
        }

        if (is_null($this->read())) {
            throw new \Exception(__METHOD__ . '; Unable to read from the socket.');
        }
    }

    /**
     * Disconnect from the server
     *
     * @throws \Exception
     */
    public function disconnect()
    {
        if (! is_resource($this->socket)) {
            throw new \Exception(__METHOD__ . '; No need to disconnect, no connection was found.');
        }

        if (! fclose($this->socket)) {
            throw new \Exception(__METHOD__ . '; Unable to disconnect.');
        }
    }

    /**
     * Login with the username and key provided by the configurations
     */
    public function login()
    {
        if (! is_resource($this->socket)) {
            $this->connect();
        }

        $command = 'CAPABILITY';
        $this->sendCommand($command);
        $response = $this->read();

        if (strpos($response, 'AUTH=XOAUTH2') !== false && $this->config->isOauth()) {
            $credentials = base64_encode("user=" . $this->config->getUsername() . "\1auth=Bearer " . $this->config->getKey() . "\1\1");
            $command = "AUTHENTICATE XOAUTH2 " . $credentials;

            $this->sendCommand($command);
            $response = $this->read();

            if (strrpos($response, '+') === 0) {
                $this->sendCommand('', true);
                $response = $this->read();
            }

            if (is_null($response)) {
                throw new \Exception(__METHOD__ . '; Unable to login.');
            }
        } elseif (! $this->config->isOauth()) {
            $credentials = $this->config->getUsername() . " " . $this->config->getKey();

            $this->sendCommand("LOGIN " . $credentials);
            $response = $this->read();

            if (is_null($response)) {
                throw new \Exception(__METHOD__ . "; Login failed.");
            }
        } else {
            throw new \Exception(__METHOD__ . '; The imap class can\'t find a supported authentication method on this server.');
        }
    }

    /**
     * Logout from the server
     * This may dosconnect and an exception will be thrown when trying to use the disconnect method
     *
     * @throws \Exception
     */
    public function logout()
    {
        if (! is_resource($this->socket)) {
            throw new \Exception(__METHOD__ . '; No connection was found.');
        }

        $this->sendCommand('LOGOUT');
        $response = $this->read();

        if (is_null($response)) {
            throw new \Exception(__METHOD__ . '; Logout failed.');
        }

        if (! fclose($this->socket)) {
            throw new \Exception(__METHOD__ . ': Failed to close socket connection.');
        }
    }

    /**
     * Get a list of mailboxes and the hierarchy delimiter
     * Runs the LIST command described in rfc3501#section-6.3.8
     *
     * @param string $referenceName (optional) Reference name
     * @param string $mailboxName   (optional) Mailbox name with possible wildcards
     *
     * @return string The LIST response from the server
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function list($referenceName = '', $mailboxName = '*')
    {
        if (! is_string($referenceName)) {
            throw new \InvalidArgumentException(__METHOD__ . "; The reference name must be a non empty string.");
        }

        if (! is_string($mailboxName)) {
            throw new InvalidArgumentException(__METHOD__ . "; The mailbox name must be a string.");
        }

        $command = 'LIST "' . $referenceName . '" "' . $mailboxName . '"';
        $this->sendCommand($command);

        $response = $this->read();

        if (is_null($response)) {
            throw new \Exception(__METHOD__ . '; Unable to list the mailboxes.');
        }

        $needle = "\r\n" . self::TAG_PREFIX . $this->tagLine;
        $strippedResponse = substr($response, 0, strrpos($response, $needle));

        return $strippedResponse;
    }

    /**
     * Get a list of subscribed mailboxes and the hierarchy delimiter
     * Runs the LSUB command described in rfc3501#section-6.3.9
     *
     * @param string $referenceName (optional) Reference name
     * @param string $mailboxName   (optional) Mailbox name with possible wildcards
     *
     * @return string The LSUB response from the server
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function lsub($referenceName = '', $mailboxName = '*')
    {
        if (! is_string($referenceName)) {
            throw new \InvalidArgumentException(__METHOD__ . "; The reference name must be a non empty string.");
        }

        if (! is_string($mailboxName)) {
            throw new InvalidArgumentException(__METHOD__ . "; The mailbox name must be a string.");
        }

        $command = 'LSUB "' . $referenceName . '" "' . $mailboxName . '"';
        $this->sendCommand($command);

        $response = $this->read();

        if (is_null($response)) {
            throw new \Exception(__METHOD__ . '; Unable to list the mailboxes.');
        }

        $strippedResponse = $this->stripTag($response);

        return $strippedResponse;
    }

    /**
     * Select a mailbox
     * Sends the SELECT command described in rfc3501#section-6.3.1
     *
     * @param string $mailbox Name of the mailbox to interact with
     *
     * @return string The server's response to the SELECT command
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function select($mailbox)
    {
        if (! is_string($mailbox) || strlen($mailbox) == 0) {
            throw new \InvalidArgumentException(__METHOD__ . "; The mailbox must be a non empty string.");
        }

        $command = 'SELECT "' . $mailbox . '"';
        $this->sendCommand($command);
        $response = $this->read();

        if (is_null($response)) {
            throw new \Exception(__METHOD__ . '; Unable to select the mailbox "' . $mailbox . '".');
        }

        $strippedResponse = $this->stripTag($response);

        return $strippedResponse;
    }

    /**
     * Fetch messages by UID ranges
     * Sends a UID FETCH command described in rfc3501#section-6.4.8
     *
     * @param string $sequenceSet   Use the UIDs to fetch messages. ({UID} or {UID:UID} to fetch a range of messages)
     * @param string $data          (optional) Describe the type of data to return. Find a list of options in rfc3501#section-6.4.5
     *
     * @return string|null The server's response with the messages requested or null when nothing was found
     *
     * @throws \InvalidArgumentException
     */
    public function uidFetch($sequenceSet, $data)
    {
        if (! is_string($sequenceSet) || strlen($sequenceSet) == 0) {
            throw new \InvalidArgumentException(__METHOD__ . "; The sequence set must be a non empty string.");
        }

        $command = 'UID FETCH ' . $sequenceSet . ' ' . $data;

        $this->sendCommand($command);

        $response = $this->read();

        if (strlen($response) == 0) {
            return null;
        }

        /*
         * If this passes it'll probably result in an error when executing the next command. When fetching messages with a range certain range, GMAIL will send the messages from 1...N.
         * Where each line ends with a + sign, indicating it's waiting for a response. I chose to send a empty response to continue.
         * @TODO Find a better solution if possible.
         */
        if (strlen($response) != 0 && strpos($response, "+", strlen($response) - 1)) {
            $this->sendCommand('', true);
            $response .= $this->read();
        }

        $strippedResponse = $this->stripTag($response);

        return $strippedResponse;
    }

    /**
     * Turn debug mode on to track the input/output commands between the server
     */
    public function debugMode()
    {
        $this->debug = true;
    }

    /**
     * Print the in/output commands
     *
     * @return string The commands and responses
     *
     * @throws \Exception
     */
    public function printDebugOutput()
    {
        if (! $this->debug) {
            throw new \Exception(__METHOD__ . '; Debug mode is off.');
        }

        echo $this->debugOutput;
    }

    /**
     * Send a command to the server
     *
     * @param string    $command    One of the commands described in rfc3501
     *                              NOTE: Do not pass a unique tag when extending this class, this is handled by the class itself
     * @param boolean   $untagged   Sometimes the server waits for input that doesn't require a tag e.g a simple newline
     *                              Set this to true if this is expected
     */
    protected function sendCommand($command, $untagged = false)
    {
        if ($untagged) {
            $fullCommand = $command . "\r\n";
        } else {
            $this->tagLine ++;
            $fullCommand = self::TAG_PREFIX . $this->tagLine . ' ' . $command . "\r\n";
        }

        if ($this->debug) {
            $this->debugOutput .= $fullCommand;
        }

        if (! fwrite($this->socket, $fullCommand)) {
            throw new Exception(__METHOD__ . '; Unable to write to socket.');
        }
    }

    /**
     * Read the server response
     * NOTE: Only use this after issuing a command
     *
     * @return string The response from the server.
     */
    protected function read()
    {
        $line = fread($this->socket, 2048);
        $this->error = null;
        $response = null;
        $tag = self::TAG_PREFIX . $this->tagLine;

        if (strpos($line, '* OK') === 0) {
            $response = $line;
        } elseif (strpos($line, $tag . " NO") !== false || strpos($line, $tag . " BAD") !== false || strpos($line, "* BAD") !== false || strpos($line, "* NO") !== false) {
            $this->error = trim($line);
        } elseif (! $line) {
            $this->error = 'Unable to read from the socket connection.';
        } else {
            $response .= $line;

            /*
             * If this passes based on the + sign at the start or end of the line, methods reading should check for this and act accordingly.
             */
            if (strpos($line, $tag) !== 0 && strpos($line, "+", strlen($line) - 1) === false && strpos($line, "+") !== 0) {
                while (strpos($line, "\r\n" . $tag) === false && strpos($line, $tag) !== 0) {
                    $line = fread($this->socket, 2048);
                    $response .= $line;
                }
            }
        }

        if ($this->debug && ! is_null($this->error) && is_null($response)) {
            $this->printDebugOutput();
            throw new \Exception(__METHOD__ . '; An error has occurred "' . $this->error . '"');
        } elseif ($this->debug && ! is_null($response)) {
            $this->debugOutput .= $response;
        }

        return $response;
    }

    /**
     * Removes the tag from the response
     *
     * @param string $response The server's response to a command
     *
     * @return string The response without the tag
     *
     * @throws \InvalidArgumentException
     */
    protected function stripTag($response)
    {
        if (! is_string($response) || strlen($response) == 0) {
            throw new \InvalidArgumentException(__METHOD__ . "; The response must be a non empty string.");
        }

        if (strpos($response, self::TAG_PREFIX . $this->tagLine) == 0) {
            $needle = self::TAG_PREFIX . $this->tagLine;
        } else {
            $needle = "\r\n" . self::TAG_PREFIX . $this->tagLine;
        }

        $strippedResponse = substr($response, 0, strrpos($response, $needle));

        return $strippedResponse;
    }
}
