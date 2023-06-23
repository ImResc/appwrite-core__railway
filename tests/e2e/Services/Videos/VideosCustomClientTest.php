<?php

namespace Tests\E2E\Services\Videos;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Client;
use Tests\E2E\Scopes\VideoCustom;
use Tests\E2E\Services\Videos\VideosPermissionsScope;
use Utopia\Database\Helpers\ID;

class VideosCustomClientTest extends Scope
{
    use ProjectCustom;
    use VideoCustom;
    use SideClient;
    use VideosPermissionsScope;

    private array $outputs = ['hls', 'dash'];

    public function testDeleteProfiles()
    {

        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals(3, $response['body']['total']);

        $profiles = $response['body']['profiles'];
        foreach ($profiles as $profile) {
            $response = $this->client->call(Client::METHOD_DELETE, '/videos/profiles/' . $profile['$id'], [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals(204, $response['headers']['status-code']);
        }

        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles/' . $profile['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('Video profile not found.', $response['body']['message']);
    }

    public function testTranscodeWithSubs()
    {
        /**
         * Create video
         */
        $response = $this->client->call(Client::METHOD_POST, '/videos', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getVideo()['$id']
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $videoId = $response['body']['$id'];

        $email = ID::unique() . '@localhost.test';
        $password = 'password';
        $user2 = $this->createUser('user2', $email, $password);

        $file = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);


        /**
         * Create preview
         */
        $response = $this->client->call(Client::METHOD_POST, '/v1/videos/' . $videoId . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'second' => 'one',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/v1/videos/' . $videoId . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'second' => 99999999,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        sleep(5);

        $response = $this->client->call(Client::METHOD_POST, '/v1/videos/' . $videoId . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'second' => 10,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(92810, $response['body']['duration']);
        /**
         * timeline vtt
         */
        $response = $this->client->call(Client::METHOD_GET, '/v1/videos/' . $videoId . '/timeline', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        preg_match_all('#\b/videos[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $response['body'], $match);
        $this->assertEquals(25, count($match[0]));

        /**
         * timeline preview image
         */
        $response = $this->client->call(Client::METHOD_GET, '/v1' . $match[0][0], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('image/jpeg', $response['headers']['content-type']);
        $this->assertEquals('miss', $response['headers']['x-appwrite-cache']);

        sleep(3);

        /**
         * timeline preview image (response from cache)
         */
        $response = $this->client->call(Client::METHOD_GET, '/v1' . $match[0][0], [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
         ]);
        $this->assertEquals('image/jpeg', $response['headers']['content-type']);
        $this->assertEquals('hit', $response['headers']['x-appwrite-cache']);

        $response = $this->client->call(Client::METHOD_GET, '/v1/videos/' . $videoId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

        $previewId = $response['body']['previewId'];

        /**
         * preview image
         */
        $response = $this->client->call(Client::METHOD_GET, '/v1/videos/' . $videoId . '/preview/' . $previewId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('image/jpeg', $response['headers']['content-type']);
        $this->assertEquals('miss', $response['headers']['x-appwrite-cache']);


        sleep(4);

        /**
         * response from cache
         */
        $response = $this->client->call(Client::METHOD_GET, '/v1/videos/' . $videoId . '/preview/' . $previewId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('image/jpeg', $response['headers']['content-type']);
        $this->assertEquals('hit', $response['headers']['x-appwrite-cache']);


        $response = $this->client->call(Client::METHOD_POST, '/v1/videos/' . $videoId . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'second' => 15,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        sleep(4);

        $response = $this->client->call(Client::METHOD_GET, '/v1/videos/' . $videoId . '/preview/' . $previewId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width' => 300,
            'height' => 100,
            'output' => 'png',
        ]);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertEquals('miss', $response['headers']['x-appwrite-cache']);

        /**
         * Create subtitles
         */
        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getSubtitle()['$id'],
            'name' => 'English',
            'code' => 'Eng',
            'default' => true,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);


        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getSubtitle()['$id'],
            'name' => 'Italian',
            'code' => 'Ita',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        /**
         * Create profiles
         */
        $response = $this->client->call(Client::METHOD_POST, '/videos/profiles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'Profile A',
            'videoBitRate' => 770,
            'audioBitRate' => 64,
            'width' => 600,
            'height' => 400,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/videos/profiles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'Profile B',
            'videoBitRate' => 570,
            'audioBitRate' => 64,
            'width' => 300,
            'height' => 200,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);
        $this->assertNotEmpty($response['body']['profiles']);
        $profiles = $response['body']['profiles'];

        foreach ($profiles as $index => $profile) {
            $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/rendition', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'output' => $this->outputs[$index % 2],
                'profileId' => $profile['$id'],
            ]);

            $this->assertEquals(204, $response['headers']['status-code']);
        }
        sleep(30);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/outputs/hls/master.m3u8', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        preg_match_all('#\b/videos[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $response['body'], $match);
        $this->assertEquals(4, count($match[0]));
        $renditionUri = $match[0][0];
        $subtitleUri  = $match[0][1];

        $response = $this->client->call(Client::METHOD_GET, $renditionUri, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        preg_match_all('#\b/videos[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $response['body'], $match);
        $this->assertEquals(10, count($match[0]));

        $segmentUri = $match[0][0];
        $response = $this->client->call(Client::METHOD_GET, $segmentUri, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, strlen($response['body']));

        $response = $this->client->call(Client::METHOD_GET, $subtitleUri, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        preg_match_all('#\b/videos[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $response['body'], $match);
        $segmentUri = $match[0][0];

        $response = $this->client->call(Client::METHOD_GET, $segmentUri, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1508, strlen($response['body']));

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/outputs/dash/master.mpd', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));


        $this->assertEquals(200, $response['headers']['status-code']);

        $xml = simplexml_load_string($response['body']);

        $this->assertEquals("PT1M32.7S", $xml->attributes()->mediaPresentationDuration);
        $this->assertEquals("PT10.0S", $xml->attributes()->maxSegmentDuration);
        $this->assertEquals("PT20.0S", $xml->attributes()->minBufferTime);
        $subsCount = 0;
        $isVideo = false;
        $isAudio = false;
        $subs[] = ['id' => '2', 'lang' => 'English',];
        $subs[] = ['id' => '3', 'lang' => 'Italian',];
        foreach ($xml->Period->AdaptationSet as $adaptation) {
            if ((string)$adaptation['contentType'] === 'video') {
                $isVideo = true;
                $this->assertEquals("50/1", $adaptation['frameRate']);
                $this->assertEquals("300", $adaptation['maxWidth']);
                $this->assertEquals("30:17", $adaptation['par']);
                $this->assertEquals("und", $adaptation['lang']);
                foreach ($adaptation->Representation as $representation) {
                    $this->assertEquals("video/mp4", $representation['mimeType']);
                    $this->assertEquals("avc1.640015", $representation['codecs']);
                    $this->assertEquals("300", $representation['width']);
                    $this->assertEquals("200", $representation['height']);
                    $this->assertEquals("20:17", $representation['sar']);
                    $this->assertEquals(10, $representation->SegmentList->SegmentURL->count());
                    $videoSegmentBaseUrl = (string)$representation->BaseURL;
                    $videoSegmentInitialization = (string)$representation->SegmentList->Initialization['sourceURL'];
                    $videoSegmentId = (string)$representation->SegmentList->SegmentURL['media'];

                    $response = $this->client->call(Client::METHOD_GET, $videoSegmentBaseUrl . $videoSegmentInitialization, [
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $this->getProject()['$id'],
                    ], $this->getHeaders());

                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertGreaterThan(0, strlen($response['body']));

                    $response = $this->client->call(Client::METHOD_GET, $videoSegmentBaseUrl . $videoSegmentId, [
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $this->getProject()['$id'],
                    ], $this->getHeaders());

                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertGreaterThan(0, strlen($response['body']));
                }
            } elseif ((string)$adaptation['contentType'] === 'audio') {
                $isAudio = true;
                foreach ($adaptation->Representation as $representation) {
                    $this->assertEquals("audio/mp4", $representation['mimeType']);
                    $this->assertEquals("mp4a.40.2", $representation['codecs']);
                    $this->assertEquals(10, $representation->SegmentList->SegmentURL->count());
                    $audioSegmentBaseUrl = (string)$representation->BaseURL;
                    $audioSegmentInitialization = (string)$representation->SegmentList->Initialization['sourceURL'];
                    $audioSegmentId = (string)$representation->SegmentList->SegmentURL['media'];

                    $response = $this->client->call(Client::METHOD_GET, $audioSegmentBaseUrl . $audioSegmentInitialization, array_merge([
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $this->getProject()['$id'],
                    ], $this->getHeaders()));

                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertGreaterThan(0, strlen($response['body']));

                    $response = $this->client->call(Client::METHOD_GET, $audioSegmentBaseUrl . $audioSegmentId, array_merge([
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $this->getProject()['$id'],
                    ], $this->getHeaders()));

                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertGreaterThan(0, strlen($response['body']));
                }
            } elseif ((string)$adaptation['mimeType'] === 'text/vtt') {
                $this->assertEquals($subs[$subsCount]['id'], $adaptation['id']);
                $this->assertEquals($subs[$subsCount]['lang'], $adaptation['lang']);
                $subsCount++;
            }
        }

        $this->assertEquals($subsCount, 2);
        $this->assertTrue($isVideo);
        $this->assertTrue($isAudio);
    }
}
