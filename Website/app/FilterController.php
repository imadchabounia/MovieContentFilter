<?php

/*
 * MovieContentFilter (https://www.moviecontentfilter.com/)
 * Copyright (c) delight.im (https://www.delight.im/)
 * Licensed under the GNU AGPL v3 (https://www.gnu.org/licenses/agpl-3.0.txt)
 */

namespace App;

use App\Lib\Edl;
use App\Lib\M3u;
use App\Lib\Mcf\Annotation;
use App\Lib\Mcf\Content;
use App\Lib\Mcf\Mcf;
use App\Lib\Mcf\WebvttTiming;
use App\Lib\Playlist\Playlist;
use App\Lib\Playlist\PlaylistItem;
use App\Lib\Playlist\Timestamp;
use App\Lib\Timing;
use App\Lib\Xspf;
use App\Lib\Mcf\WebvttTimestamp;
use Delight\Foundation\App;

class FilterController extends Controller {

	public static function customizeDownload(App $app, $id) {
		self::ensureAuthenticated($app);

		// retrieve the number of individual preferences that the user has set up
		$numberOfPreferences = $app->db()->selectValue(
			'SELECT COUNT(*) FROM preferences WHERE user_id = ?',
			[ $app->auth()->id() ]
		);

		// if no preferences have been set up yet
		if ($numberOfPreferences === 0) {
			// ask the user to do so first
			$app->flash()->warning('Looks like you haven\'t set up any preferences yet. Please do so first, which allows for customized filters that are just for you.');
			$app->redirect('/preferences');
			exit;
		}

		$id = $app->ids()->decode(trim($id));

		$work = $app->db()->selectRow(
			'SELECT type, title, year, canonical_start_time, canonical_end_time FROM works WHERE id = ?',
			[ $id ]
		);

		$params = $work;

		$params['id'] = $id;

		if ($params['canonical_start_time'] !== null) {
			$params['canonical_start_time'] = (string) WebvttTimestamp::fromSeconds($params['canonical_start_time']);
		}

		if ($params['canonical_end_time'] !== null) {
			$params['canonical_end_time'] = (string) WebvttTimestamp::fromSeconds($params['canonical_end_time']);
		}

		if ($work['type'] === 'episode') {
			$params['series'] = $app->db()->selectRow(
				'SELECT b.id AS parent_id, b.title AS parent_title, a.season, a.episode_in_season FROM works_relations AS a JOIN works AS b ON a.parent_work_id = b.id WHERE a.child_work_id = ? LIMIT 0, 1',
				[ $id ]
			);

			$params['suggestedFilename'] = $params['series']['parent_title']. ' - Season ' . sprintf('%02d', $params['series']['season']) . ' - Episode ' . sprintf('%02d', $params['series']['episode_in_season']);
		}
		else {
			$params['suggestedFilename'] = $work['title'];
		}

		echo $app->view('download.html', $params);
	}

	public static function sendDownload(App $app, $id) {
		self::ensureAuthenticated($app);

		$id = $app->ids()->decode(trim($id));

		$format = $app->input()->post('format');
		$videoFileUri = $app->input()->post('video-source');
		$suggestedFilename = $app->input()->post('suggested-filename');

		if ($format === 'mcf') {
			$suggestedFilenameWithExtension = $suggestedFilename . ' - MCF.mcf';
			$downloadMimeType = 'text/plain';

			$work = $app->db()->selectRow(
				'SELECT a.title, a.year, a.type, b.season, b.episode_in_season, a.imdb_url FROM works AS a LEFT JOIN works_relations AS b ON b.child_work_id = a.id WHERE a.id = ?',
				[ $id ]
			);

			$annotations = $app->db()->select(
				"SELECT a.start_position, a.end_position, GROUP_CONCAT(b.name, '=', c.name, '=', d.name, ?, a.id ORDER BY b.name ASC, a.id ASC SEPARATOR ',') AS content_list FROM annotations AS a JOIN categories AS b ON b.id = a.category_id JOIN severities AS c ON c.id = a.severity_id JOIN channels AS d ON d.id = a.channel_id WHERE a.work_id = ? GROUP BY a.start_position, a.end_position ORDER BY a.start_position ASC LIMIT 0, 500",
				[
					Content::COMMENT_SEPARATOR . $app->url('/annotations/'),
					$id
				]
			);

			$out = new Mcf();
			$out->setMetaTitle($work['title']);
			$out->setMetaYear($work['year']);
			$out->setMetaType($work['type']);
			$out->setMetaSeason($work['season']);
			$out->setMetaEpisode($work['episode_in_season']);
			$out->setMetaSource($app->url('/works/' . $app->ids()->encode($id)));
			$out->setMetaImdb('http://www.imdb.com/' . $work['imdb_url']);

			if ($annotations !== null) {
				foreach ($annotations as $annotation) {
					// encode annotation IDs in content
					$annotation['content_list'] = preg_replace_callback('/(?<=\/)([0-9]+)(?=,|$)/', function ($match) use ($app) {
						return $app->ids()->encode($match[1]);
					}, $annotation['content_list']);

					$annotationObj = new Annotation(
						new WebvttTiming(
							WebvttTimestamp::fromSeconds($annotation['start_position']),
							WebvttTimestamp::fromSeconds($annotation['end_position'])
						)
					);

					$contentEntries = explode(',', $annotation['content_list']);

					foreach ($contentEntries as $contentEntry) {
						$annotationObj->addContent(
							Content::parse($contentEntry)
						);
					}

					$out->addAnnotation($annotationObj);
				}
			}

			// scale to the maximum duration so that all fixed-point numbers in the output have maximum precision
			$out->changeTime(
				WebvttTimestamp::fromSeconds(0),
				WebvttTimestamp::fromComponents(99, 59, 59, 999)
			);
		}
		else {
			$mode = $app->input()->post('mode');

			$annotations = $app->db()->select(
				"SELECT MIN(a.id) AS id, a.start_position, a.end_position, GROUP_CONCAT(b.name SEPARATOR ',') AS channel_list FROM annotations AS a JOIN channels AS b ON b.id = a.channel_id JOIN preferences AS c ON c.user_id = ? AND c.category_id = a.category_id AND c.severity_id <= a.severity_id WHERE a.work_id = ? GROUP BY a.start_position, a.end_position ORDER BY a.start_position ASC LIMIT 0, 500",
				[
					$app->auth()->id(),
					$id
				]
			);

			$filenameModeInfix = ($mode === 'preview' ? 'Preview' : 'Filter');

			if ($format === 'xspf') {
				$suggestedFilenameWithExtension = $suggestedFilename . ' - '.$filenameModeInfix.' - XSPF.xspf';
				$downloadMimeType = 'application/xspf+xml';

				$out = new Xspf($videoFileUri, $app->url('/works/' . $app->ids()->encode($id)));
			}
			elseif ($format === 'm3u') {
				$suggestedFilenameWithExtension = $suggestedFilename . ' - '.$filenameModeInfix.' - M3U.m3u';
				$downloadMimeType = 'audio/x-mpegurl';

				$out = new M3u($videoFileUri, $app->url('/works/' . $app->ids()->encode($id)));
			}
			elseif ($format === 'edl') {
				$suggestedFilenameWithExtension = $suggestedFilename . ' - '.$filenameModeInfix.' - EDL.edl';
				$downloadMimeType = 'text/plain';

				$out = new Edl($app->url('/works/' . $app->ids()->encode($id)));
			}
			else {
				throw new \RuntimeException('Unknown format `'.$format.'`');
			}

			if ($annotations !== null) {
				foreach ($annotations as $annotation) {
					$annotationObj = new PlaylistItem(
						$app->url('/annotations/' . $app->ids()->encode($annotation['id'])),
						new Timing(
							Timestamp::fromSeconds($annotation['start_position']),
							Timestamp::fromSeconds($annotation['end_position'])
						)
					);

					$channels = explode(',', $annotation['channel_list']);

					foreach ($channels as $channel) {
						$annotationObj->addChannel($channel);
					}

					$out->addAnnotation($annotationObj);
				}
			}

			// scale the time to match the desired playback time
			$syncStartTime = $app->input()->post('synchronization-start-time');
			$syncEndTime = $app->input()->post('synchronization-end-time');
			$syncStartTimestamp = WebvttTimestamp::parse($syncStartTime);
			$syncEndTimestamp = WebvttTimestamp::parse($syncEndTime);
			$out->changeTime($syncStartTimestamp, $syncEndTimestamp);

			// if the filter is a playlist
			if ($out instanceof Playlist) {
				// fill all gaps between annotations
				$out->fillUp();
			}

			// if the preview mode has been selected
			if ($mode === 'preview') {
				// invert the filter
				$out->setInverted(true);
			}
		}

		$app->downloadContent((string) $out, $suggestedFilenameWithExtension, $downloadMimeType);
	}

}
