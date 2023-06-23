<?php

use Appwrite\Event\Delete;
use Appwrite\Event\Video;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Files;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\View;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Appwrite\Extend\Exception;
use Utopia\Image\Image;
use Utopia\Storage\Device;
use Utopia\Validator;
use Utopia\Validator\Boolean;
use Utopia\Validator\Numeric;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\Swoole\Request;

/**
 * Validate file Permissions
 *
 * @param Database $dbForProject
 * @param string $bucketId
 * @param string $fileId
 * @param string $mode
 * @return Document $file
 * @throws Exception|Throwable
 */
function validateFilePermissions(Database $dbForProject, string $bucketId, string $fileId, string $mode): Document
{

    $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

    if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)) {
        throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
    }

    $fileSecurity = $bucket->getAttribute('fileSecurity', false);
    $validator = new Authorization(Database::PERMISSION_READ);
    $valid = $validator->isValid($bucket->getRead());


    if (!$fileSecurity && !$valid) {
        throw new Exception(Exception::USER_UNAUTHORIZED);
    }

    if ($fileSecurity && !$valid) {
        $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
    } else {
        $file = Authorization::skip(fn() => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));
    }

    if ($file->isEmpty()) {
        throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
    }

    return $file;
}

App::post('/v1/videos')
    ->desc('Create Video')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.write')
    ->label('audits.event', 'video.create')
    ->label('audits.resource', 'video/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/videos/create.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VIDEO)
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new CustomId(), 'File ID. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(action: function (string $bucketId, string $fileId, Request $request, Response $response, Document $project, Database $dbForProject, string $mode) {

        $file = validateFilePermissions($dbForProject, $bucketId, $fileId, $mode);

        if (
            !str_starts_with($file->getAttribute('mimeType'), 'video/') &&
            !str_starts_with($file->getAttribute('mimeType'), 'audio/') &&
            $file->getAttribute('mimeType') !== 'application/ogg'
        ) {
            throw new Exception(Exception::VIDEO_NOT_VALID);
        }

        $video = Authorization::skip(function () use ($dbForProject, $bucketId, $file) {
            return $dbForProject->createDocument('videos', new Document([
                'bucketId' => $file->getAttribute('bucketId'),
                'bucketInternalId' => $file->getAttribute('bucketInternalId'),
                'fileId'  => $file->getId(),
                'fileInternalId' => $file->getInternalId(),
                'size' => $file->getAttribute('sizeOriginal'),
            ]));
        });

        (new Video())
            ->setAction('timeline')
            ->setProject($project)
            ->setVideo($video)
            ->trigger();

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($video, Response::MODEL_VIDEO);
    });

App::get('/v1/videos/:videoId')
    ->desc('Get video ')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/videos/get-video.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VIDEO)
    ->param('videoId', '', new UID(), 'Video  unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $videoId, Response $response, Database $dbForProject, string $mode) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));

        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);

        $response->dynamic($video, Response::MODEL_VIDEO);
    });

App::put('/v1/videos/:videoId')
    ->desc('Update video')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.write')
    ->label('audits.event', 'video.update')
    ->label('audits.resource', 'video/{request.videoId}')
    ->label('sdk.namespace', 'videos')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'update')
    ->label('sdk.description', '/docs/references/videos/update.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_VIDEO)
    ->param('videoId', '', new UID(), 'Video unique ID.')
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new CustomId(), 'File ID. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $videoId, $bucketId, $fileId, Response $response, Document $project, Database $dbForProject, string $mode) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));

        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);

        $file = validateFilePermissions($dbForProject, $bucketId, $fileId, $mode);

        if (
            !str_starts_with($file->getAttribute('mimeType'), 'video/') &&
            !str_starts_with($file->getAttribute('mimeType'), 'audio/') &&
            $file->getAttribute('mimeType') !== 'application/ogg'
        ) {
            throw new Exception(Exception::VIDEO_NOT_VALID);
        }

        $video = Authorization::skip(fn() =>
        $dbForProject->updateDocument('videos', $videoId, new Document([
            'bucketId'  => $file->getAttribute('bucketId'),
            'bucketInternalId' => $file->getAttribute('bucketInternalId'),
            'fileId' => $file->getId(),
            'fileInternalId' => $file->getInternalId(),
            'size'      => $file->getAttribute('sizeOriginal'),
            'previewId' =>  null,
            'previewInternalId' =>  null,
            'duration' =>  null,
            'width' =>  null,
            'height' =>  null,
            'videoCodec' =>  null,
            'videoBitRate' =>  null,
            'videoFrameRate' =>  null,
            'audioCodec' =>  null,
            'audioBitRate' =>  null,
            'audioSampleRate' => null,
        ])));

        $response->dynamic($video, Response::MODEL_VIDEO);
    });

App::delete('/v1/videos/:videoId')
    ->desc('Delete video')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.write')
    ->label('audits.event', 'video.delete')
    ->label('audits.resource', 'video/{request.videoId}')
    ->label('sdk.namespace', 'videos')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/videos/delete.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('videoId', '', new UID(), 'Video unique ID.')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('deletes')
    ->action(function (string $videoId, Response $response, Document $project, Database $dbForProject, string $mode, Delete $deletes) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));
        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);

        $deleted = $dbForProject->deleteDocument('videos', $videoId);

        if (!$deleted) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR);
        }

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($video);

        $response->noContent();
    });

App::get('/v1/videos')
    ->desc('Get video list')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/videos/get-video-list.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VIDEO)
    ->param('queries', [], new Files(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Files::ALLOWED_ATTRIBUTES), true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, Response $response, Database $dbForProject) {

        $queries = Query::parseQueries($queries);
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
        $cursor = reset($cursor);
        if ($cursor) {
            $fileId = $cursor->getValue();
            $cursorDocument = Authorization::skip(fn() => $dbForProject->getDocument('videos', $fileId));
            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "File '{$fileId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'videos' => $dbForProject->find('videos', $queries),
            'total'  => $dbForProject->count('videos', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_VIDEO_LIST);
    });

App::get('/v1/videos/:videoId/timeline')
    ->desc('Get video timeline')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'getTimeline')
    ->label('sdk.description', '/docs/references/videos/get-video-timeline.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', '*/*')
    ->label('sdk.methodType', 'location')
    ->param('videoId', '', new UID(), 'Video  unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deviceVideos')
    ->inject('mode')
    ->action(function (string $videoId, Response $response, Database $dbForProject, Device $deviceVideos, string $mode) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));

        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);

        $timeline = Authorization::skip(fn () => $dbForProject->find('videos_previews', [Query::equal('type', ['sprite'])]));

        if (empty($timeline)) {
            throw new Exception(Exception::VIDEO_TIMELINE_NOT_FOUND);
        }

        $data = $deviceVideos->read($deviceVideos->getPath($video->getId() . '/timeline') . '/timeline.vtt');
        $response->setContentType('text/vtt')
            ->send($data);
    });


App::get('/v1/videos/:videoId/preview/:previewId')
    ->desc('Get video file preview')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.read')
    ->label('cache', true)
    ->label('cache.resourceType', 'video/{request.videoId}')
    ->label('cache.resource', 'preview/{request.previewId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'getVideoImagePreview')
    ->label('sdk.description', '/docs/references/storage/get-video-image-preview.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_IMAGE)
    ->label('sdk.methodType', 'location')
    ->param('videoId', '', new UID(), 'Video  unique ID.')
    ->param('previewId', '', new UID(), 'Preview  unique ID.')
    ->param('width', 0, new Range(0, 4000), 'Resize preview image width, Pass an integer between 0 to 4000.', true)
    ->param('height', 0, new Range(0, 4000), 'Resize preview image height, Pass an integer between 0 to 4000.', true)
    ->param('output', '', new WhiteList(\array_keys(Config::getParam('storage-outputs')), true), 'Output format type (jpeg, jpg, png, gif and webp).', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('deviceVideos')
    ->action(function (string $videoId, string $previewId, int $width, int $height, string $output, Request $request, Response $response, Document $project, Database $dbForProject, string $mode, Device $deviceVideos) {

        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));
        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);

        $preview = Authorization::skip(fn() => $dbForProject->getDocument('videos_previews', $previewId));

        if ($preview->isEmpty()) {
            throw new Exception(Exception::VIDEO_PREVIEW_NOT_FOUND);
        }

        if ((!str_contains($request->getAccept(), 'image/webp')) && ('webp' === $output)) { // Fallback webp to jpeg when no browser support
            $output = 'jpg';
        }

        $outputs = Config::getParam('storage-outputs');
        $path    = $preview->getAttribute('path') . $preview->getAttribute('name');
        $mime    = $deviceVideos->getFileMimeType($path);
        $type    = \strtolower(\pathinfo($path, PATHINFO_EXTENSION));

        if (empty($output)) {
            $output = empty($type) ? (array_search($mime, $outputs) ?? 'jpg') : $type;
        }

        $source = $deviceVideos->read($path);
        $image  = new Image($source);
        $image->crop((int)$width, (int)$height, Image::GRAVITY_CENTER);

        $data = $image->output($output, 100);
        $contentType = (\array_key_exists($output, $outputs)) ? $outputs[$output] : $outputs['jpg'];
        $response
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + 60 * 60 * 24 * 30) . ' GMT')
            ->setContentType($contentType)
            ->file($data)
        ;

        unset($image);
    });

App::post('/v1/videos/:videoId/subtitles')
    ->desc('Add subtitle to video')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.write')
    ->label('audits.event', 'subtitle.create')
    ->label('audits.resource', 'video/{response.videoId}/subtitle/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'addSubtitle')
    ->label('sdk.description', '/docs/references/videos/add-subtitle.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SUBTITLE)
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('bucketId', '', new CustomId(), 'Subtitle bucket unique ID.')
    ->param('fileId', '', new CustomId(), 'Subtitle file unique ID.')
    ->param('name', '', new Text(32), 'Subtitle name.')
    ->param('code', '', new WhiteList(\array_column(Config::getParam('locale-languages'), 'code2')), 'Subtitle ISO 639-2  3 letters alpha code.')
    ->param('default', false, new Boolean(true), 'Default subtitle.')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(action: function (string $videoId, string $bucketId, string $fileId, string $name, string $code, bool $default, Request $request, Response $response, Document $project, Database $dbForProject, string $mode) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));

        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);
        $file = validateFilePermissions($dbForProject, $bucketId, $fileId, $mode);

        if (!in_array($file->getAttribute('mimeType'), ['text/vtt','text/plain'])) {
            throw new Exception(Exception::VIDEO_SUBTITLE_NOT_VALID);
        }

        $subtitle = Authorization::skip(fn() =>
            $dbForProject->createDocument('videos_subtitles', new Document([
                'videoId'   => $video->getId(),
                'videoInternalId' => $video->getInternalId(),
                'bucketId'  => $file->getAttribute('bucketId'),
                'bucketInternalId'  => $file->getAttribute('bucketInternalId'),
                'fileId'    => $file->getId(),
                'fileInternalId' => $file->getInternalId(),
                'name'      => $name,
                'code'      => $code,
                'default'   => $default,
            ])));

        (new Video())
            ->setAction('subtitle')
            ->setProject($project)
            ->setVideo($video)
            ->setSubtitle($subtitle)
            ->trigger();

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($subtitle, Response::MODEL_SUBTITLE);
    });

App::get('/v1/videos/:videoId/subtitles')
    ->desc('Get video subtitles')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'getSubtitles')
    ->label('sdk.description', '/docs/references/videos/get-subtitles.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SUBTITLE_LIST)
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($videoId, Response $response, Database $dbForProject) {

        $query =  [
            Query::equal('videoId', [$videoId]),
        ];

        $subtitles = Authorization::skip(fn () => $dbForProject->find('videos_subtitles', $query));

        if (empty($subtitles)) {
            throw new Exception(Exception::VIDEO_SUBTITLE_NOT_FOUND);
        }

        $response->dynamic(new Document([
            'total' => $dbForProject->count('videos_subtitles', $query, APP_LIMIT_COUNT),
            'subtitles' => $subtitles,
        ]), Response::MODEL_SUBTITLE_LIST);
    });

App::patch('/v1/videos/:videoId/subtitles/:subtitleId')
    ->desc('Update video subtitle')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.write')
    ->label('audits.event', 'subtitle.update')
    ->label('audits.resource', 'video/{response.videoId}/subtitle/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'updateSubtitle')
    ->label('sdk.description', '/docs/references/videos/update-subtitle.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SUBTITLE)
    ->param('subtitleId', null, new UID(), 'Video subtitle unique ID.')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('bucketId', '', new CustomId(), 'Subtitle bucket unique ID.')
    ->param('fileId', '', new CustomId(), 'Subtitle file unique ID.')
    ->param('name', '', new Text(32), 'Subtitle customized name.')
    ->param('code', '', new Text(3), 'Subtitle  ISO 639-2 three letters alpha code.')
    ->param('default', false, new Boolean(true), 'Default subtitle.')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(action: function (string $subtitleId, string $videoId, string $bucketId, string $fileId, string $name, string $code, bool $default, Response $response, Document $project, Database $dbForProject, string $mode) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));

        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);

        $file = validateFilePermissions($dbForProject, $bucketId, $fileId, $mode);

        if (!in_array($file->getAttribute('mimeType'), ['text/vtt','text/plain'])) {
            throw new Exception(Exception::VIDEO_SUBTITLE_NOT_VALID);
        }

        $code = strtolower($code);
        $languages = Config::getParam('locale-languages');
        $found = array_search($code, array_column($languages, 'code2'));

        if (!$found) {
            throw new Exception(Exception::VIDEO_LANGUAGE_CODE_NOT_VALID);
        }

        $subtitle = Authorization::skip(fn() => $dbForProject->getDocument('videos_subtitles', $subtitleId));

        if ($subtitle->isEmpty()) {
            throw new Exception(Exception::VIDEO_SUBTITLE_NOT_FOUND);
        }

        $subtitle->setAttribute('videoId', $video->getId())
                 ->setAttribute('videoInternalId', $video->getInternalId())
                 ->setAttribute('bucketId', $file->getAttribute('bucketId'))
                 ->setAttribute('bucketInternalId', $file->getAttribute('bucketInternalId'))
                 ->setAttribute('fileId', $file->getId())
                 ->setAttribute('fileInternalId', $file->getInternalId())
                 ->setAttribute('name', $name)
                 ->setAttribute('code', $code)
                 ->setAttribute('default', $default);

        $subtitle = Authorization::skip(fn() => $dbForProject->updateDocument('videos_subtitles', $subtitle->getId(), $subtitle));

        (new Video())
            ->setAction('subtitle')
            ->setProject($project)
            ->setVideo($video)
            ->setSubtitle($subtitle)
            ->trigger();

        $response->dynamic($subtitle, Response::MODEL_SUBTITLE);
    });

App::delete('/v1/videos/:videoId/subtitles/:subtitleId')
    ->desc('Delete video subtitle')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.write')
    ->label('audits.event', 'subtitle.delete')
    ->label('audits.resource', 'video/{request.videoId}/subtitle/{request.subtitleId}')
    ->label('sdk.namespace', 'videos')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'deleteSubtitle')
    ->label('sdk.description', '/docs/references/videos/delete-subtitle.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('videoId', '', new UID(), 'Video  unique ID.')
    ->param('subtitleId', '', new UID(), 'Subtitle unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $videoId, string $subtitleId, Response $response, Database $dbForProject, string $mode) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));

        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);

        $subtitle = Authorization::skip(fn() => $dbForProject->getDocument('videos_subtitles', $subtitleId));

        if ($subtitle->isEmpty()) {
            throw new Exception(Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        $deleted = $dbForProject->deleteDocument('videos_subtitles', $subtitleId);

        if (!$deleted) {
            throw new Exception('Failed to remove video subtitle', 500, Exception::GENERAL_SERVER_ERROR);
        }

        $segments = Authorization::skip(fn() => $dbForProject->find('videos_subtitles_segments', [
            Query::equal('subtitleInternalId', [$subtitle->getInternalId()]),
        ]));

        foreach ($segments as $segment) {
            $dbForProject->deleteDocument('videos_subtitles_segments', $segment->getId());
        }

        $response->noContent();
    });

App::post('/v1/videos/:videoId/rendition')
    ->alias('/v1/videos/:videoId/rendition', [])
    ->desc('Create video rendition')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.write')
    ->label('audits.event', 'rendition.create')
    ->label('audits.resource', 'video/{response.videoId}/rendition/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'createRendition')
    ->label('sdk.description', '/docs/references/videos/create-rendition.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('profileId', '', new CustomId(), 'Profile unique ID.')
    ->param('output', '', new WhiteList(['hls', 'dash']), 'output name')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('mode')
    ->action(action: function (string $videoId, string $profileId, string $output, Request $request, Response $response, Database $dbForProject, Document $project, string $mode) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));

        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video->getAttribute('bucketId'), $video->getAttribute('fileId'), $mode);

        $profile = Authorization::skip(fn() => $dbForProject->getDocument('videos_profiles', $profileId));
        if ($profile->isEmpty()) {
            throw new Exception(Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        (new Video())
            ->setAction('encode')
            ->setProject($project)
            ->setVideo($video)
            ->setProfile($profile)
            ->setOutput($output)
            ->trigger();

        $response->noContent();
    });

App::get('/v1/videos/:videoId/renditions/:renditionId')
    ->desc('Get a single video rendition')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'getRendition')
    ->label('sdk.description', '/docs/references/videos/get-rendition.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_RENDITION)
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('renditionId', null, new UID(), 'Rendition unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function ($videoId, $renditionId, Response $response, Database $dbForProject, string $mode) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));

        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);

        $rendition = Authorization::skip(fn() => $dbForProject->getDocument('videos_renditions', $renditionId));

        if ($rendition->isEmpty()) {
            throw new Exception('Video rendition not found', 404, Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $response->dynamic($rendition, Response::MODEL_RENDITION);
    });

App::get('/v1/videos/:videoId/renditions')
    ->desc('Get video renditions')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'getRenditions')
    ->label('sdk.description', '/docs/references/videos/get-renditions.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_RENDITION_LIST)
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $videoId, Response $response, Database $dbForProject, string $mode) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));

        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);

        $queries = [
            Query::equal('videoInternalId', [$video->getInternalId()]),
            Query::equal('status', ['ready']),
        ];

        $renditions = Authorization::skip(fn () => $dbForProject->find('videos_renditions', $queries));

        $response->dynamic(new Document([
            'total'      => $dbForProject->count('videos_renditions', $queries, APP_LIMIT_COUNT),
            'renditions' => $renditions,
        ]), Response::MODEL_RENDITION_LIST);
    });

App::get('/v1/videos/renditions')
    ->desc('Get all videos renditions')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'getAllRenditions')
    ->label('sdk.description', '/docs/references/videos/get-all-renditions.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_RENDITION_LIST)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (Response $response, Database $dbForProject) {

        $renditions = Authorization::skip(fn () => $dbForProject->find('videos_renditions'));

        $response->dynamic(new Document([
            'total'      => $dbForProject->count('videos_renditions', [], APP_LIMIT_COUNT),
            'renditions' => $renditions,
        ]), Response::MODEL_RENDITION_LIST);
    });

App::delete('/v1/videos/:videoId/renditions/:renditionId')
    ->desc('Delete video rendition')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.write')
    ->label('event', 'videos.[videoIdId].renditions.[renditionId].delete')
    ->label('audits.event', 'rendition.delete')
    ->label('audits.resource', 'video/{request.videoId}/rendition/{request.$id}')
    ->label('sdk.namespace', 'videos')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'deleteRendition')
    ->label('sdk.description', '/docs/references/videos/delete-rendition.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('videoId', '', new UID(), 'Video unique ID.')
    ->param('renditionId', '', new UID(), 'Video rendition unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('deviceVideos')
    ->action(function (string $videoId, string $renditionId, Response $response, Database $dbForProject, string $mode, Device $deviceVideos) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));

        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);

        $rendition = Authorization::skip(fn() => $dbForProject->getDocument('videos_renditions', $renditionId));
        if ($rendition->isEmpty()) {
            throw new Exception(Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $deleted = $dbForProject->deleteDocument('videos_renditions', $renditionId);

        if (!$deleted) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR);
        }

        Authorization::skip(fn() => $dbForProject->deleteDocument('videos_renditions', $rendition->getId()));
        if (!empty($rendition['path'])) {
            $deviceVideos->deletePath($rendition['path']);
        }

        $response->noContent();
    });

App::get('/v1/videos/:videoId/outputs/:output/:fileName')
    ->desc('Get video master renditions manifest')
    ->groups(['api', 'videos'])
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'videos')
    ->label('origin', '*')
    ->label('sdk.method', 'getMasterManifest')
    ->label('sdk.description', '/docs/references/videos/get-master-manifest.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', '*/*')
    ->label('sdk.methodType', 'location')
    ->label('scope', 'videos.read')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('output', '', new WhiteList(['hls', 'dash']), 'output name')
    ->param('fileName', '', new WhiteList(['master.m3u8', 'master.mpd']), 'manifest filename')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $videoId, string $output, string $fileName, Response $response, Database $dbForProject, string $mode) {

        $dbStartTime = microtime(true);
        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));

        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);

        $renditions = Authorization::skip(fn () => $dbForProject->find('videos_renditions', [
            Query::equal('videoInternalId', [$video->getInternalId()]),
            Query::equal('status', ['ready']),
            Query::equal('output', [$output]),
        ]));

        if (empty($renditions)) {
            throw new Exception(Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $baseUrl = TMP_HOST . 'v1/videos/' . $videoId . '/outputs/' . $output;
        $subtitles = Authorization::skip(fn() => $dbForProject->find('videos_subtitles', [
            Query::equal('videoInternalId', [$video->getInternalId()]),
            Query::equal('status', ['ready']),
        ]));

        $_renditions = $_subtitles  = [];

        if ($output === 'hls') {
            foreach ($subtitles ?? [] as $subtitle) {
                $_subtitles[] = [
                    'name' => $subtitle->getAttribute('name'),
                    'code' => $subtitle->getAttribute('code'),
                    'default' => !empty($subtitle->getAttribute('default')) ? 'YES' : 'NO',
                    'uri' => $baseUrl . '/subtitles/' . $subtitle->getId() . '/subtitles.m3u8',
                ];
            }

            foreach ($renditions as $rendition) {
                $uri = $codecs = $resolution = $bandwidth = null;
                $_audios = [];
                $metadata = $rendition->getAttribute('metadata');
                $streams = $metadata['hls'];
                $audioLang = null;
                foreach ($streams as $i => $stream) {
                    /**
                     * Two soundtracks with same language (Dolby)
                     */
                    if ($stream['type'] === 'audio') {
                        if ($stream['language'] === $audioLang) {
                            continue;
                        }

                        $_audios[] = [
                            'type' => 'group_audio',
                            'name' => $stream['language'],
                            'default' => ($i === 0) ? 'YES' : 'NO',
                            'language' => $stream['language'],
                            'uri' => $baseUrl . '/renditions/' . $rendition->getId() . '/streams/' . $stream['id'] . '/playlist.m3u8',
                        ];

                        $audioLang = $stream['language'];
                    } elseif ($stream['type'] === 'video') {
                        $uri = $baseUrl . '/renditions/' . $rendition->getId() . '/streams/' . $stream['id'] . '/playlist.m3u8';
                        $resolution = $stream['resolution'] ?? $rendition->getAttribute('width') . 'x' . $rendition->getAttribute('height');
                        $bandwidth  = $stream['bandwidth']  ?? ($rendition->getAttribute('videoBitRate') + $rendition->getAttribute('audioBitRate')) * 1024;
                        $codecs     = $stream['codecs'] ?? null;
                    }
                }

                $_renditions[] = [
                    'bandwidth'  => $bandwidth,
                    'resolution' => $resolution,
                    'codecs' => $codecs,
                    'name' => $rendition->getAttribute('name'),
                    'uri'  => rtrim($uri),
                    'subs' => !empty($_subtitles) ? 'subs' : null,
                    'audio' => !empty($_audios) ? 'group_audio' : null,
                ];
            }

            $template = new View(__DIR__ . '/../../views/videos/hls-master.phtml');
            $template->setParam('audios', $_audios);
            $template->setParam('subtitles', $_subtitles);
            $template->setParam('renditions', $_renditions);
            $response
                ->setContentType('application/x-mpegurl')
                ->send($template->render(false))
            ;
        } else {
            $adaptationId = 0;
            foreach ($renditions as $rendition) {
                $metadata = $rendition->getAttribute('metadata');
                $xml = simplexml_load_string($metadata['mpd']);
                if (empty($xml)) {
                    continue;
                }
                $attributes = (array)$xml->attributes();
                $mpd = $attributes['@attributes'] ?? [];
                $streamId = 0;
                $audioLang = null;
                foreach ($xml->Period->AdaptationSet ?? [] as $adaptation) {

                    /**
                    * Two soundtracks with same language (Dolby)
                    */
                    if ((string)$adaptation['contentType'] === 'audio') {
                        if ($adaptation['lang'] == $audioLang) {
                            continue;
                        }

                         $audioLang = (string)$adaptation['lang'];
                    }

                    $representation = [];
                    $representation['id'] = $streamId;
                    $attributes = (array)$adaptation->Representation->attributes();
                    $representation['attributes'] = $attributes['@attributes'] ?? [];
                    $attributes = (array)$adaptation->Representation->SegmentList->attributes();
                    $representation['segmentList']['attributes'] = $attributes['@attributes'] ?? [];
                    $segments = Authorization::skip(fn () => $dbForProject->find('videos_renditions_segments', [
                        Query::equal('renditionInternalId', [$rendition->getInternalId()]),
                        Query::equal('streamId', [$streamId]),
                        Query::orderAsc('_id'),
                        Query::limit(5000),
                    ]));

                    if (count($segments) === 0) {
                        continue;
                    }

                    foreach ($segments ?? [] as $segment) {
                        if ($segment->getAttribute('isInit')) {
                            $representation['segmentList']['init'] = $segment->getId() . '/segment.m4s';
                            continue;
                        }

                        $representation['segmentList']['media'][] = $segment->getId() . '/segment.m4s';
                    }

                    $attributes = (array)$adaptation->attributes();
                    $_renditions[] =  [
                        'attributes' => $attributes['@attributes'] ?? [],
                        'id' => $adaptationId,
                        'baseUrl' => $baseUrl . '/renditions/' . $rendition->getId() . '/' . 'segments/',
                        'representation' => $representation,
                    ];
                    $adaptationId++;
                    $streamId++;
                }
            }

            foreach ($subtitles ?? [] as $subtitle) {
                $_subtitles[] = [
                    'id' => $adaptationId,
                    'baseUrl' => $baseUrl . '/subtitles/' . $subtitle->getId() . '/subtitle.vtt',
                    'name' => $subtitle->getAttribute('name'),
                ];
                $adaptationId++;
            }

            $template = new View(__DIR__ . '/../../views/videos/dash.phtml');
            $template->setParam('mpd', $mpd);
            $template->setParam('renditions', $_renditions);
            $template->setParam('subtitles', $_subtitles);
            $response
                ->setContentType('application/dash+xml')
                ->send($template->render(false))
            ;
        }
    });

App::get('/v1/videos/:videoId/outputs/:output/renditions/:renditionId/streams/:streamId/:fileName')
    ->desc('Get video rendition manifest')
    ->groups(['api', 'videos'])
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'getManifest')
    ->label('sdk.description', '/docs/references/videos/get-manifest.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', '*/*')
    ->label('sdk.methodType', 'location')
    ->label('origin', '*')
    ->label('scope', 'videos.read')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('output', '', new WhiteList(['hls']), 'Output name.')
    ->param('renditionId', null, new UID(), 'Rendition unique ID.')
    ->param('streamId', 0, new Range(0, 10), 'Stream ID.')
    ->param('fileName', '', new WhiteList(['playlist.m3u8']), 'Playlist filename')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $videoId, string $output, string $renditionId, string $streamId, string $fileName, Response $response, Database $dbForProject, string $mode) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));

        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);

        $rendition = Authorization::skip(fn () => $dbForProject->findOne('videos_renditions', [
            Query::equal('_uid', [$renditionId]),
            Query::equal('status', ['ready']),
        ]));

        if ($rendition->isEmpty() || empty($rendition)) {
            throw new Exception(Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $segments = Authorization::skip(fn () => $dbForProject->find('videos_renditions_segments', [
            Query::equal('renditionInternalId', [$rendition->getInternalId()]),
            Query::equal('streamId', [$streamId]),
            Query::orderAsc('_id'),
            Query::limit(5000)
        ]));

        if (empty($segments)) {
            throw new Exception(Exception::VIDEO_RENDITION_SEGMENT_NOT_FOUND);
        }

        $_segments = [];
        foreach ($segments as $segment) {
            $_segments[] = [
                'duration' => $segment->getAttribute('duration'),
                'url' => TMP_HOST . 'v1/videos/' . $videoId . '/outputs/' . $output . '/renditions/' . $renditionId . '/segments/' . $segment->getId() . '/segment.ts',
            ];
        }

        $template = new View(__DIR__ . '/../../views/videos/hls.phtml');
        $template->setParam('targetDuration', $rendition->getAttribute('targetDuration'));
        $template->setParam('segments', $_segments);
        $response->setContentType('application/x-mpegurl')
            ->send($template->render(false));
    });

App::get('/v1/videos/:videoId/outputs/:output/renditions/:renditionId/segments/:segmentId/:fileName')
    ->desc('Get video rendition segment')
    ->groups(['api', 'videos'])
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'getRenditionSegment')
    ->label('sdk.description', '/docs/references/videos/get-rendition-segment.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', '*/*')
    ->label('sdk.methodType', 'location')
    ->label('origin', '*')
    ->label('scope', 'videos.read')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('output', '', new WhiteList(['hls', 'dash']), 'Output name')
    ->param('renditionId', '', new UID(), 'Rendition unique ID.')
    ->param('segmentId', '', new UID(), 'Segment unique ID.')
    ->param('fileName', '', new WhiteList(['segment.ts', 'segment.m4s']), 'Segment filename')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deviceVideos')
    ->action(function (string $videoId, string $output, string $renditionId, string $segmentId, string $fileName, Response $response, Database $dbForProject, Device $deviceVideos) {

        $segment = Authorization::skip(fn() => $dbForProject->getDocument('videos_renditions_segments', $segmentId));
        if ($segment->isEmpty()) {
            throw new Exception(Exception::VIDEO_RENDITION_SEGMENT_NOT_FOUND);
        }

        $data = $deviceVideos->read($segment->getAttribute('path') .  $segment->getAttribute('fileName'));

        if ($output === 'hls') {
            $response->setContentType('video/MP2T')
                ->send($data);
        }

        $response->setContentType('video/iso.segment')
            ->send($data);
    });

App::get('/v1/videos/:videoId/outputs/:output/subtitles/:subtitleId/:fileName')
    ->desc('Get video subtitle')
    ->groups(['api', 'videos'])
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'getSubtitle')
    ->label('sdk.description', '/docs/references/videos/get-subtitle.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', '*/*')
    ->label('sdk.methodType', 'location')
    ->label('origin', '*')
    ->label('scope', 'videos.read')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('output', '', new WhiteList(['hls', 'dash']), 'Protocol name')
    ->param('subtitleId', '', new UID(), 'Subtitle unique ID.')
    ->param('fileName', '', new WhiteList(['subtitles.m3u8', 'subtitle.vtt']), 'Subtitle filename')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deviceVideos')
    ->inject('mode')
    ->action(function (string $videoId, string $output, string $subtitleId, $fileName, Response $response, Database $dbForProject, Device $deviceVideos, string $mode) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));
        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);
        //var_dump('$subtitleId=',$subtitleId);
        $subtitle = Authorization::skip(fn () => $dbForProject->findOne('videos_subtitles', [
            Query::equal('_uid', [$subtitleId]),
            Query::equal('status', ['ready']),
        ]));

        if ($subtitle->isEmpty() || empty($subtitle)) {
            throw new Exception(Exception::VIDEO_SUBTITLE_NOT_FOUND);
        }

        if ($output === 'dash') {
            $data = $deviceVideos->read($deviceVideos->getPath($subtitle->getAttribute('videoId')) . '/subtitles/'  . $subtitle->getId() . '.vtt');
            $response->setContentType('text/vtt')
                ->send($data);
        }

        $segments = Authorization::skip(fn () => $dbForProject->find('videos_subtitles_segments', [
            Query::equal('subtitleId', [$subtitleId]),
        ]));
        //var_dump('$segments=',$segments);
        if (empty($segments)) {
            throw new Exception(Exception::VIDEO_SUBTITLE_SEGMENT_NOT_FOUND);
        }

        $_segments = [];
        foreach ($segments as $segment) {
            $_segments[] = [
                'duration' => $segment->getAttribute('duration'),
                'url' => TMP_HOST . 'v1/videos/' . $videoId . '/outputs/' . $output . '/subtitles/' . $subtitleId . '/segments/' . $segment->getId() . '/subtitle.vtt',
            ];
        }

        $template = new View(__DIR__ . '/../../views/videos/hls-subtitles.phtml');
        $template->setParam('targetDuration', $subtitle->getAttribute('targetDuration'));
        $template->setParam('segments', $_segments);
        $response->setContentType('application/x-mpegurl')
                 ->send($template->render(false));
    });

App::get('/v1/videos/:videoId/outputs/:output/subtitles/:subtitleId/segments/:segmentId/:fileName')
    ->desc('Get video subtitle segment')
    ->groups(['api', 'videos'])
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'getSubtitleSegment')
    ->label('sdk.description', '/docs/references/videos/get-subtitle-segment.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', '*/*')
    ->label('sdk.methodType', 'location')
    ->label('origin', '*')
    ->label('scope', 'videos.read')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('output', '', new WhiteList(['hls', 'dash']), 'output name')
    ->param('subtitleId', '', new UID(), 'Subtitle unique ID.')
    ->param('segmentId', '', new UID(), 'Segment unique ID.')
    ->param('fileName', '', new WhiteList(['subtitle.vtt']), 'Subtitle filename')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deviceVideos')
    ->inject('mode')
    ->action(function (string $videoId, string $output, string $subtitleId, string $segmentId, string $fileName, Response $response, Database $dbForProject, Device $deviceVideos, string $mode) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));
        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode);

        $segment = Authorization::skip(fn() => $dbForProject->getDocument('videos_subtitles_segments', $segmentId));
        if ($segment->isEmpty()) {
            throw new Exception(Exception::VIDEO_SUBTITLE_SEGMENT_NOT_FOUND);
        }

        $data = $deviceVideos->read($segment->getAttribute('path') .  $segment->getAttribute('fileName'));
        $response->setContentType('text/vtt')
            ->send($data);
    });

App::post('/v1/videos/profiles')
    ->desc('Create video profile')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.write')
    ->label('audits.event', 'profile.create')
    ->label('audits.resource', 'profile/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'createProfile')
    ->label('sdk.description', '/docs/references/videos/create-profile.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROFILE)
    ->param('name', null, new Text(128), 'Video profile name.')
    ->param('videoBitRate', '', new Range(32, 5000), 'Video profile bitrate in Kbps.')
    ->param('audioBitRate', '', new Range(32, 5000), 'Audio profile bit rate in Kbps.')
    ->param('width', '', new Range(6, 3000), 'Video profile width.')
    ->param('height', '', new Range(6, 3000), 'Video  profile height.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(action: function (string $name, string $videoBitRate, string $audioBitRate, string $width, string $height, Response $response, Database $dbForProject) {

            $profile = Authorization::skip(function () use ($dbForProject, $name, $videoBitRate, $audioBitRate, $width, $height) {
                return $dbForProject->createDocument('videos_profiles', new Document([
                    'name'          => $name,
                    'videoBitRate'  => (int)$videoBitRate,
                    'audioBitRate'  => (int)$audioBitRate,
                    'width'         => (int)$width,
                    'height'        => (int)$height,
                ]));
            });

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($profile, Response::MODEL_PROFILE);
    });

App::patch('/v1/videos/profiles/:profileId')
    ->desc('Update video  profile')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.write')
    ->label('audits.event', 'profile.update')
    ->label('audits.resource', 'profile/{request.profileId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'updateProfile')
    ->label('sdk.description', '/docs/references/videos/update-profile.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROFILE)
    ->param('profileId', '', new UID(), 'Video profile unique ID.')
    ->param('name', null, new Text(128), 'Video profile name.')
    ->param('videoBitRate', '', new Range(64, 4000), 'Video profile bitrate in Kbps.')
    ->param('audioBitRate', '', new Range(64, 4000), 'Audio profile bit rate in Kbps.')
    ->param('width', '', new Range(100, 2000), 'Video profile width.')
    ->param('height', '', new Range(100, 2000), 'Video  profile height.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(action: function (string $profileId, string $name, string $videoBitRate, string $audioBitRate, string $width, string $height, Response $response, Database $dbForProject) {

        $profile = Authorization::skip(fn() => $dbForProject->getDocument('videos_profiles', $profileId));
        if ($profile->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $profile->setAttribute('name', $name)
                 ->setAttribute('videoBitRate', (int)$videoBitRate)
                 ->setAttribute('audioBitRate', (int)$audioBitRate)
                 ->setAttribute('width', (int)$width)
                 ->setAttribute('height', (int)$height);

        $profile = Authorization::skip(fn() => $dbForProject->updateDocument('videos_profiles', $profile->getId(), $profile));

        $response->dynamic($profile, Response::MODEL_PROFILE);
    });

App::get('/v1/videos/profiles/:profileId')
    ->desc('Get video profile')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'getProfile')
    ->label('sdk.description', '/docs/references/videos/get-profile.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROFILE)
    ->param('profileId', '', new UID(), 'Video profile unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $profileId, Response $response, Database $dbForProject) {

        $profile = Authorization::skip(fn() => $dbForProject->getDocument('videos_profiles', $profileId));
        if ($profile->isEmpty()) {
            throw new Exception(Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        $response->dynamic($profile, Response::MODEL_PROFILE);
    });

App::get('/v1/videos/profiles')
    ->desc('Get all video profiles')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'videos')
    ->label('sdk.method', 'getProfiles')
    ->label('sdk.description', '/docs/references/videos/get-profiles.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROFILE_LIST)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (Response $response, Database $dbForProject) {

        $profiles = Authorization::skip(fn () => $dbForProject->find('videos_profiles'));

        $response->dynamic(new Document([
            'total' => $dbForProject->count('videos_profiles', [], APP_LIMIT_COUNT),
            'profiles' => $profiles,
        ]), Response::MODEL_PROFILE_LIST);
    });

App::delete('/v1/videos/profiles/:profileId')
    ->desc('Delete video profile')
    ->groups(['api', 'videos'])
    ->label('scope', 'videos.write')
    ->label('audits.event', 'profile.delete')
    ->label('audits.resource', 'profile/{request.profileId}')
    ->label('sdk.namespace', 'videos')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'deleteProfile')
    ->label('sdk.description', '/docs/references/videos/delete-profile.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('profileId', '', new UID(), 'Video profile unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $profileId, Response $response, Database $dbForProject) {

        $profile = Authorization::skip(fn() => $dbForProject->getDocument('videos_profiles', $profileId));
        if ($profile->isEmpty()) {
            throw new Exception(Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        $deleted = $dbForProject->deleteDocument('videos_profiles', $profileId);

        if (!$deleted) {
            throw new Exception('Failed to delete video profile', 500, Exception::GENERAL_SERVER_ERROR);
        }

        $response->noContent();
    });
