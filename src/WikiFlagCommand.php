<?php

namespace Webtrees\Geodata;

use DOMDocument;
use DomXPath;
use GuzzleHttp\Client;
use Masterminds\HTML5;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WikiFlagCommand extends AbstractBaseCommand
{
    /** @var InputInterface */
    private $input;

    /** @var OutputInterface */
    private $output;

    /**
     * Command details, options and arguments
     */
    public function configure()
    {
        $this
            ->setName('import')
            ->setDescription('Import a flag')
            ->setHelp('Import a flag from wikimedia')
            ->setDefinition(
                new InputDefinition([
                    new InputArgument(
                        'place',
                        InputArgument::REQUIRED,
                        'Name of place (in English)'
                    ),
                    new InputArgument(
                        'flag',
                        InputArgument::REQUIRED,
                        'The URL fragment after "https://commons.wikimedia.org/wiki/File:"'
                    ),
                ])
            );
    }

    /**
     * Run the command
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input  = $input;
        $this->output = $output;

        $place = $input->getArgument('place');
        $flag  = $input->getArgument('flag');
        $url   = 'https://commons.wikimedia.org/wiki/File:' . $flag;

        $licence = $place . '/LICENCE.md';

        $source = $this->geographicDataFilesystem();
        if (!$source->has($licence)) {
            $source->write($licence, '');
        }

        if (!preg_match('|^https://commons.wikimedia.org/wiki/File:.+.svg$|', $url)) {
            $this->output->writeln('URL must be of the form https://commons.wikimedia.org/wiki/File:XXXXX.svg');

            return;
        }

        // Fetch and parse the page from wikimedia
        $html  = $this->download($url);
        //$html  = file_get_contents('debug.html');
        $html5 = new HTML5();
        $dom   = $html5->loadHTML($html);

        // Strip the default namespace.
        // https://stackoverflow.com/questions/25484217/xpath-with-html5lib-in-php
        $namespace = $dom->documentElement->getAttributeNode("xmlns")->nodeValue;
        $dom->documentElement->removeAttributeNS($namespace,"");
        $dom->loadXML($dom->saveXML());

        $xpath = new DomXPath($dom);

        // From reverse-engineering the "use this file on the web" link
        $paths = [
            '//div[@id="file"]/a',
            '//div[@id="file"]/div/div/a',
            '//div[@class="fullMedia"]//a',
        ];
        $file_url = '';
        foreach ($paths as $path) {
            $node = $xpath->query($path)->item(0);
            if ($node !== null) {
                $file_url = $node->getAttribute('href');
                continue;
            }
        }
        $this->output->writeln('File URL: ' . $file_url);

        $author_node = $xpath->query('//td[@id="fileinfotpl_aut"]/../td/a')->item(0);
        if (!empty($author_node)) {
            $author = str_replace('User:', '', $author_node->nodeValue);
            $this->output->writeln('Author: ' . $author);
        } else {
            $author = 'Unknown';
        }

        $licence_node = $xpath->query('//span[@class="licensetpl_short"]')->item(0);
        $licence = $licence_node->nodeValue;
        $this->output->writeln('Licence: ' . $licence);

        $svg = $this->download($file_url);
        $source->put($place . '/flag.svg', $svg);

        $licence = '* [flag.svg](' . $url . ') - ' . $licence . ' - ' . $author . "\n";
        $source->put($place . '/LICENCE.md', $licence);

        return;
    }

    private function download(string $url): string {
        $client = new Client();
        $result = $client->request('GET', $url);
        $bytes  = strlen($result->getBody());

        if ($result->getStatusCode() === 200) {
            $this->output->writeln('Fetched ' . $bytes . '  bytes from ' . $url);
            return $result->getBody();
        } else {
            $this->output->writeln('Failed to download URL. Status code ' . $result->getStatusCode);
            return '';
        }
    }
}
