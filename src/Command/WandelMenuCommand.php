<?php
/**
 * @author Stefan Beier <stefan.beier@meinfernbus.de>
 */
namespace MFB\SlackReactor\Command;

use Cilex\Command\Command;
use DOMDocument;
use DOMXPath;
use React\EventLoop\Factory;
use React\Http\Request;
use React\Http\Response;
use React\Socket\Server;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Class WandelMenuCommand
 */
class WandelMenuCommand extends Command
{

    const COMMAND_NAME = 'stefan:slack:wandel';

    /**
     * Post wandel menu only every 5 minutes.
     */
    const SPAM_FILTER = 300;

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this->setName(self::COMMAND_NAME);
        $this->setDefinition([new InputOption('--config', null, InputOption::VALUE_REQUIRED, 'Path to configuration file'),]);
    }

    /**
     * {@inheritDoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (!$configFilePath = realpath($input->getOption('config'))) {
            throw new \RuntimeException('Configuration file path is invalid.');
        }

        $container = $this->getContainer();

        $settings = Yaml::parse(file_get_contents($configFilePath))['settings'];

        $container['settings'] = $settings;
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $loop            = Factory::create();
        $socket          = new Server($loop);
        $container       = $this->getContainer();
        $wandelSettings  = $container['settings']['servers']['wandel'];
        $http            = new \React\Http\Server($socket);
        $lastRequested   = 0;
        $spamWarningSent = false;

        $app = function (Request $request, Response $response) use ($wandelSettings, &$lastRequested, &$spamWarningSent) {
            $request->on('data', function ($data) use ($request, $response, $wandelSettings, &$lastRequested, &$spamWarningSent) {

                parse_str($data, $postParams);

                // If there is nothing, do nothing.
                if ($data == '' || $postParams['token'] != $wandelSettings['slack_token']) {
                    $response->writeHead(404);
                    $response->end();

                    return;
                }

                // Spam filter and send spam warning text.
                if (time() - $lastRequested < WandelMenuCommand::SPAM_FILTER) {

                    if (!$spamWarningSent) {
                        $payload = json_encode(['text' => 'Hey, do not spam the WandelBot!']);
                        $response->writeHead(200);
                        $response->end($payload);
                        $spamWarningSent = true;

                        return;
                    }

                    return;
                }

                // All the credits to @alberteddu for this!
                $a = file_get_contents($wandelSettings['wandel_1']);
                $b = new DOMDocument;
                $b->loadHTML($a);
                $c             = new DOMXPath($b);
                $e             = $c->query('//*[@id="m3"]/ul/li/ul/li/span/a/@href');
                $wandelMenuUrl = 'http://www.wandel-restaurant.de/' . $e->item(0)->nodeValue;

                $responseText    = $wandelMenuUrl . ' - Brought you by horriblesolutions.com';
                $payload         = json_encode(['text' => $responseText]);
                $spamWarningSent = false;
                $lastRequested   = time();

                $response->writeHead(200);
                $response->end($payload);

            });

        };

        $http->on('request', $app);

        $host = $wandelSettings['host'];
        $port = $wandelSettings['port'];

        $socket->listen($port, $host);
        $output->writeln(sprintf('Server started on %s:%s', $host, $port));
        $loop->run();
    }

}
