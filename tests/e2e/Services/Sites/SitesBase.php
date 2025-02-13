<?php

namespace Tests\E2E\Services\Sites;

use Appwrite\Tests\Async;
use CURLFile;
use Tests\E2E\Client;
use Utopia\CLI\Console;
use Utopia\Database\Query;

trait SitesBase
{
    use Async;

    protected function setupSite(mixed $params): string
    {
        $site = $this->client->call(Client::METHOD_POST, '/sites', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $params);

        $this->assertEquals($site['headers']['status-code'], 201, 'Setup site failed with status code: ' . $site['headers']['status-code'] . ' and response: ' . json_encode($site['body'], JSON_PRETTY_PRINT));

        $siteId = $site['body']['$id'];

        return $siteId;
    }

    protected function setupDeployment(string $siteId, mixed $params): string
    {
        $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $params);
        $this->assertEquals($deployment['headers']['status-code'], 202, 'Setup deployment failed with status code: ' . $deployment['headers']['status-code'] . ' and response: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments/' . $deploymentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('ready', $deployment['body']['status'], 'Deployment status is not ready, deployment: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        }, 100000, 500);

        return $deploymentId;
    }

    protected function cleanupSite(string $siteId): void
    {
        $site = $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals($site['headers']['status-code'], 204);
    }

    protected function cleanupDeployment(string $siteId, string $deploymentId): void
    {
        $deployment = $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals($deployment['headers']['status-code'], 204);
    }

    protected function createSite(mixed $params): mixed
    {
        $site = $this->client->call(Client::METHOD_POST, '/sites', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $site;
    }

    protected function updateSite(mixed $params): mixed
    {
        $site = $this->client->call(Client::METHOD_PUT, '/sites/' . $params['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $site;
    }

    protected function createVariable(string $siteId, mixed $params): mixed
    {
        $variable = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $variable;
    }

    protected function getVariable(string $siteId, string $variableId): mixed
    {
        $variable = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $variable;
    }

    protected function listVariables(string $siteId, mixed $params = []): mixed
    {
        $variables = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $variables;
    }

    protected function updateVariable(string $siteId, string $variableId, mixed $params): mixed
    {
        $variable = $this->client->call(Client::METHOD_PUT, '/sites/' . $siteId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $variable;
    }

    protected function deleteVariable(string $siteId, string $variableId): mixed
    {
        $variable = $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $variable;
    }

    protected function getSite(string $siteId): mixed
    {
        $site = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $site;
    }

    protected function getDeployment(string $siteId, string $deploymentId): mixed
    {
        $deployment = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $deployment;
    }

    protected function getLog(string $siteId, $logId): mixed
    {
        $log = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/logs/' . $logId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $log;
    }

    protected function listSites(mixed $params = []): mixed
    {
        $sites = $this->client->call(Client::METHOD_GET, '/sites', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $sites;
    }

    protected function listDeployments(string $siteId, $params = []): mixed
    {
        $deployments = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $deployments;
    }

    protected function listLogs(string $siteId, mixed $params = []): mixed
    {
        $logs = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $logs;
    }

    protected function packageSite(string $site, string $format = 'gzip'): CURLFile
    {
        $extension = '';
        $command = '';
        $header = '';

        switch ($format) {
            case 'gzip':
                $extension = 'tar.gz';
                $command = 'tar --exclude code.tar.gz -czf code.tar.gz .';
                $header = 'application/x-gzip';
                break;
            case 'zip':
                $extension = 'zip';
                $command = 'zip -x code.zip -r code.zip .';
                $header = 'application/zip';
                break;
            default:
                throw new \Exception('Invalid package format');
        }

        $folderPath = realpath(__DIR__ . '/../../../resources/sites') . "/$site";
        $filePath = "$folderPath/code." . $extension;

        $stdout = '';
        $stderr = '';
        $exitCode = Console::execute("cd $folderPath && " . $command, '', $stdout, $stderr);

        $this->assertEquals(0, $exitCode);
        $this->assertEmpty($stderr);

        if (filesize($filePath) > 1024 * 1024 * 5) {
            throw new \Exception('Code package is too large. Use the chunked upload method instead.');
        }

        return new CURLFile($filePath, $header, \basename($filePath));
    }

    protected function createDeployment(string $siteId, mixed $params = []): mixed
    {
        $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $deployment;
    }

    protected function getSiteUsage(string $siteId, mixed $params): mixed
    {
        $usage = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $usage;
    }

    protected function getTemplate(string $templateId)
    {
        $template = $this->client->call(Client::METHOD_GET, '/sites/templates/' . $templateId, array_merge([
            'content-type' => 'application/json'
        ], $this->getHeaders()));

        return $template;
    }

    protected function deleteSite(string $siteId): mixed
    {
        $site = $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $site;
    }

    protected function getSiteDomain(string $siteId): string
    {
        $rules = $this->client->call(Client::METHOD_GET, '/proxy/rules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('resourceId', [$siteId])->toString(),
                Query::equal('resourceType', ['site'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $rules['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($rules['body']['rules']));
        $this->assertNotEmpty($rules['body']['rules'][0]['domain']);

        $domain = $rules['body']['rules'][0]['domain'];

        return $domain;
    }
}
