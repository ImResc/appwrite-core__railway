<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments\Builds\Download;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Swoole\Request;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getDeploymentBuildDownload';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/functions/:functionId/deployments/:deploymentId/build/download')
            ->desc('Download build')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.read')
            ->label('sdk', new Method(
                namespace: 'functions',
                name: 'getDeploymentBuildDownload',
                description: <<<EOT
                Get a function build content by its unique ID. The endpoint response return with a 'Content-Disposition: attachment' header that tells the browser to start downloading the file to user downloads directory.
                EOT,
                auth: [AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE
                    )
                ],
                type: MethodType::LOCATION,
                contentType: ContentType::ANY,
            ))
            ->param('functionId', '', new UID(), 'Function ID.')
            ->param('deploymentId', '', new UID(), 'Deployment ID.')
            ->inject('response')
            ->inject('request')
            ->inject('dbForProject')
            ->inject('deviceForBuilds')
            ->callback([$this, 'action']);
    }

    public function action(string $functionId, string $deploymentId, Response $response, Request $request, Database $dbForProject, Device $deviceForBuilds)
    {
        $function = $dbForProject->getDocument('functions', $functionId);
        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId'));
        if ($build->isEmpty()) {
            throw new Exception(Exception::BUILD_NOT_FOUND);
        }

        $path = $build->getAttribute('path', '');
        if (!$deviceForBuilds->exists($path)) {
            throw new Exception(Exception::BUILD_NOT_FOUND);
        }

        $response
            ->setContentType('application/gzip')
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT') // 45 days cache
            ->addHeader('X-Peak', \memory_get_peak_usage())
            ->addHeader('Content-Disposition', 'attachment; filename="' . $deploymentId . '.tar.gz"');

        $size = $deviceForBuilds->getFileSize($path);
        $rangeHeader = $request->getHeader('range');

        if (!empty($rangeHeader)) {
            $start = $request->getRangeStart();
            $end = $request->getRangeEnd();
            $unit = $request->getRangeUnit();

            if ($end === null) {
                $end = min(($start + MAX_OUTPUT_CHUNK_SIZE - 1), ($size - 1));
            }

            if ($unit !== 'bytes' || $start >= $end || $end >= $size) {
                throw new Exception(Exception::STORAGE_INVALID_RANGE);
            }

            $response
                ->addHeader('Accept-Ranges', 'bytes')
                ->addHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
                ->addHeader('Content-Length', $end - $start + 1)
                ->setStatusCode(Response::STATUS_CODE_PARTIALCONTENT);

            $response->send($deviceForBuilds->read($path, $start, ($end - $start + 1)));
        }

        if ($size > APP_STORAGE_READ_BUFFER) {
            for ($i = 0; $i < ceil($size / MAX_OUTPUT_CHUNK_SIZE); $i++) {
                $response->chunk(
                    $deviceForBuilds->read(
                        $path,
                        ($i * MAX_OUTPUT_CHUNK_SIZE),
                        min(MAX_OUTPUT_CHUNK_SIZE, $size - ($i * MAX_OUTPUT_CHUNK_SIZE))
                    ),
                    (($i + 1) * MAX_OUTPUT_CHUNK_SIZE) >= $size
                );
            }
        } else {
            $response->send($deviceForBuilds->read($path));
        }
    }
}
