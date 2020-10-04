<?php

declare(strict_types=1);

namespace Glued\Calendar\Controllers;

use Carbon\Carbon;
use Glued\Core\Classes\Json\JsonResponseBuilder;
use Glued\Core\Controllers\AbstractTwigController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Respect\Validation\Validator as v;
use Sabre\VObject;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Exception\HttpNotFoundException;
use \Opis\JsonSchema\Loaders\File as JSL;

class CalendarController extends AbstractTwigController
{

    // ==========================================================
    // HELPERS
    // ==========================================================

    private function sql_insert_with_json($table, $row) {
        $this->db->startTransaction(); 
        $id = $this->db->insert($table, $row);
        $err = $this->db->getLastErrno();
        if ($id) {
          $updt = $this->db->rawQuery("UPDATE `".$table."` SET `c_json` = JSON_SET(c_json, '$.id', ?) WHERE c_uid = ?", Array ((int)$id, (int)$id));
          $err += $this->db->getLastErrno();
        }
        if ($err === 0) { $this->db->commit(); } else { $this->db->rollback(); throw new \Exception(__('Database error: ')." ".$err." ".$this->db->getLastError()); }
        return (int)$id;
    }

    // ==========================================================
    // EVENTS
    // ==========================================================

    public function sources_sync(Request $request, Response $response, array $args = []): Response {

        // Get info about calendar
        // TODO add rbac here
        $this->db->where('c_uid', (int)$args['uid']);
        $source = $this->db->getOne('t_calendar_sources');
        if (!$source) {
            $builder = new JsonResponseBuilder('calendar.sources.sync', 1);
            $payload = $builder->withCode(404)->build();
            return $response->withJson($payload);
        }

        $t_remote = microtime(true);
        $uri = json_decode($source['c_json'], true)['uri'];
        $calendar = VObject\Reader::read( fopen( $uri, 'r' ) );
        echo 'read remote: '.(microtime(true)-$t_remote);
        set_time_limit(300);


        foreach($calendar->vevent as $event) {

            // (Re)initialize helper variables and unset Google specific noise
            $curr = null;
            $test = null;
            $updt = true;
            $json = [];
            $new = [];
            $new_rev = [];
            $revs = [];

            // Unset noise
            unset($event->DTSTAMP); // current timestamp (of grabbing the ical from remote)
            unset($event->VALARM);  // we don't support importing alarms ATM

            // Standardize data
            $json['uid'] = (string)$event->uid;
            $json['recurrence-id'] = (string)$event->recurrence_id;
            $json['created'] = (string)$event->created;
            $json['summary'] = (string)$event->summary;
            $json['description'] = (string)$event->description;
            $json['last-modified'] = strtotime((string)$event->last_modified);
            $json['dstart'] = (string)$event->dtstart;
            $json['dtend'] = (string)$event->dtend;
            $json['rrule'] = json_encode($event->rrule);
            $json['rdate'] = json_encode($event->rdate);
            $json['exrule'] = json_encode($event->exrule);
            $json['exdate'] = json_encode($event->exdate);
            $json['location'] = (string)$event->location;
            $json['sequence'] = (string)$event->sequence;
            $json['attach'] = (string)($event->attach);
            if (empty($json['dtend'])) { $json['dtend'] = (string)$event->dtstart; }

            // We keep track of changes in ical events. With every sync,
            // the raw data of updated events will be stored as new revisions.
            // Every revision is identified by its timestamp and hash too.
            $new_rev['ts'] = time();
            $new_rev['data'] = (string)$event->serialize();
            $new_rev['hash'] = hash('sha256', $new_rev['data']);

            // Get event from db, if we already habe it
            $this->db->where('c_source_id', (int)$args['uid']);
            $this->db->where('c_object_uid', (string)$event->uid);
            $curr = $this->db->getOne('t_calendar_objects');

            if ($curr) {
                // uptdate branch
                if ($new_rev['hash'] != $curr['c_object_hash']) {

                    // update only when the hash of the new event revision
                    // is unknown (an unknown revision)
                    // TODO this may break if someone changes event A to B and back to A (relies on remote setting the $event->last_modified correctly)
                    $revs = json_decode($curr['c_revisions'], true);
                    foreach ($revs as $test) {
                        if ($test['hash'] == $new_rev['hash']) { $updt = false; }
                    }
                    
                    if ($updt == true) {
                        $revs[] = $new_rev;
                        $json['id'] = $curr['c_uid'];
                        $new['c_json'] = json_encode($json);
                        $new['c_revision_counter'] = $curr['c_revision_counter'] + 1;
                        $new['c_revisions'] = json_encode($revs);
                        $new['c_object_hash'] = $new_rev['hash'];
                        $this->db->where('c_uid', $curr['c_uid']);
                        $this->db->update('t_calendar_objects', $new);
                    }

                } else {

                    // do nothing, we got current data in db already
                    
                }
            } else {
                // create branch
                $new['c_attr'] = '{}';
                $new['c_json'] = json_encode($json);
                $revs[] = $new_rev;
                $new['c_revisions'] = json_encode($revs);
                $new['c_revision_counter'] = 0;
                $new['c_source_id'] = (int)$args['uid'];
                $new['c_object_uid'] = $json['uid'];
                $new['c_object_hash'] = $new_rev['hash'];
                $new['c_object'] = 'prc';
                $new['c_object_sequence'] = $json['sequence'];
                echo " <i style='color: green;'>insert done</i>";
                try { $new_ins = $this->sql_insert_with_json('t_calendar_objects', $new); } catch (Exception $e) { 
                    throw new HttpInternalServerErrorException($request, $e->getMessage());  
                }
            }

            // TODO check for deleted events

         

            
            /*
        $min_start = false;
        $max_end = false;            
        $carb_now = Carbon::now();
            $uid = (string)$event->created.(string)$event->uid;
            $carb_created = Carbon::createFromFormat('Ymd\THis\Z', (string)$event->created);
            $events[$uid]['start'] = strtotime((string)$event->dtstart);
            $events[$uid]['end'] = strtotime($dtend);
            $events[$uid]['created'] = (string)$event->created;
            $events[$uid]['hrcreated'] = $carb_created->diffForHumans($carb_now);

            if ( ($min_start === false) or $events[$uid]['start'] < $min_start ) { $min_start = $events[$uid]['start'] ; }
            if ( ($max_end === false) or $events[$uid]['end'] > $max_end ) { $max_end = $events[$uid]['end'] ; }
        }
        
        $period = new \DatePeriod(
            new \DateTime(date(DATE_ATOM, $min_start)),
            new \DateInterval('P1D'),
            new \DateTime(date(DATE_ATOM, $max_end))
        );
*/
        }
        return $response;
    }


    public function events_list_ui(Request $request, Response $response, array $args = []): Response {
        return $this->render($response, 'Calendar/Views/list.twig', [
            'pageTitle' => 'Calendar'
        ]);
    }

    public function events_get(Request $request, Response $response, array $args = []): Response {
        return $this->render($response, 'Calendar/Views/list.twig', [
            'pageTitle' => 'Calendar', 'out' => $out
        ]);
    }

    // ==========================================================
    // SOURCES UI
    // ==========================================================

    public function sources_list_ui(Request $request, Response $response, array $args = []): Response {
        // Since we don't have RBAC implemented yet, we're passing all domains
        // to the view. The view uses them in the form which adds/modifies a view.
        // 
        // TODO - write a core function that will get only the domains for a given user
        // so that we dont copy paste tons of code around and we don't present sources out of RBAC
        // scope of a user.
        // 
        // TODO - preseed domains on installation with at least one domain
        $domains = $this->db->get('t_core_domains');

        return $this->render($response, 'Calendar/Views/sources.twig', [
            'domains' => $domains
        ]);
    }

    // ==========================================================
    // SOURCES API
    // ==========================================================

    private function sql_sources_list() {
        $data = $this->db->rawQuery("
            SELECT
                c_domain_id as 'domain',
                t_calendar_sources.c_user_id as 'user',
                t_core_users.c_name as 'user_name',
                t_core_domains.c_name as 'domain_name',
                t_calendar_sources.c_json->>'$.id' as 'id',
                t_calendar_sources.c_json->>'$._s' as '_s',
                t_calendar_sources.c_json->>'$._v' as '_v',
                t_calendar_sources.c_json->>'$.uri' as 'uri',
                t_calendar_sources.c_json->>'$.name' as 'name',
                t_calendar_sources.c_json->>'$.driver' as 'driver'
            FROM `t_calendar_sources` 
            LEFT JOIN t_core_users ON t_calendar_sources.c_user_id = t_core_users.c_uid
            LEFT JOIN t_core_domains ON t_calendar_sources.c_domain_id = t_core_domains.c_uid
        ");
        return $data;
    }


    public function sources_list(Request $request, Response $response, array $args = []): Response
    {
        $builder = new JsonResponseBuilder('calendar.sources', 1);
        $payload = $builder->withData((array)$this->sql_sources_list())->withCode(200)->build();
        return $response->withJson($payload);
        // TODO handle errors
    }


    public function sources_patch(Request $request, Response $response, array $args = []): Response {
        $builder = new JsonResponseBuilder('calendar.sources', 1);

        // Get patch data
        $req = $request->getParsedBody();
        $req['user'] = (int)$_SESSION['core_user_id'];
        $req['id'] = (int)$args['uid'];
        
        // Get old data
        $this->db->where('c_uid', $req['id']);
        $doc = $this->db->getOne('t_calendar_sources', ['c_json'])['c_json'];
        if (!$doc) { throw new HttpBadRequestException( $request, __('Bad source ID.')); }
        $doc = json_decode($doc);

        // TODO replace this lame acl with something propper.
        if($doc->user != $req['user']) { throw new HttpForbiddenException( $request, 'You can only edit your own calendar sources.'); }

        // Patch old data
        $doc->uri = $req['uri'];
        $doc->name = $req['name'];
        $doc->domain = (int)$req['domain'];
        $doc->driver = $req['driver'];
        // TODO if $doc->domain is patched here, you have to first test, if user has access to the domain

        // load the json schema and validate data against it
        $loader = new JSL("schema://calendar/", [ __ROOT__ . "/glued/Calendar/Controllers/Schemas/" ]);
        $schema = $loader->loadSchema("schema://calendar/calendar.v1.schema");
        $result = $this->jsonvalidator->schemaValidation($doc, $schema);

        if ($result->isValid()) {
            $row = [ 'c_json' => json_encode($doc) ];
            $this->db->where('c_uid', $req['id']);
            $id = $this->db->update('t_calendar_sources', $row);
            if (!$id) { throw new HttpInternalServerErrorException( $request, __('Updating the calendar source failed.')); }
        } else { throw new HttpBadRequestException( $request, __('Invalid calendar source data.')); }

        // Success
        $payload = $builder->withData((array)$req)->withCode(200)->build();
        return $response->withJson($payload, 200);  
    }


    public function sources_post(Request $request, Response $response, array $args = []): Response {
        $builder = new JsonResponseBuilder('calendar.sources', 1);
        $req = $request->getParsedBody();
        $req['user'] = (int)$_SESSION['core_user_id'];
        $req['id'] = 0;
         
        // TODO check again if user is member of a domain that was submitted
        if ( isset($req['domain']) ) { $req['domain'] = (int) $req['domain']; }
        if ( isset($req['private']) ) { $req['private'] = (bool) $req['private']; }
        // convert body to object
        $req = json_decode(json_encode((object)$req));
        // TODO replace manual coercion above with a function to recursively cast types of object values according to the json schema object (see below)       
    
        // load the json schema and validate data against it
        $loader = new JSL("schema://calendar/", [ __ROOT__ . "/glued/Calendar/Controllers/Schemas/" ]);
        $schema = $loader->loadSchema("schema://calendar/calendar.v1.schema");
        $result = $this->jsonvalidator->schemaValidation($req, $schema);

        if ($result->isValid()) {
            $row = array (
                'c_domain_id' => (int)$req->domain, 
                'c_user_id' => (int)$req->user,
                'c_json' => json_encode($req),
                'c_attr' => '{}'
            );
            try { $req->id = $this->sql_insert_with_json('t_calendar_sources', $row); } catch (Exception $e) { 
                throw new HttpInternalServerErrorException($request, $e->getMessage());  
            }
            $payload = $builder->withData((array)$req)->withCode(200)->build();
            return $response->withJson($payload, 200);
        } else {
            $reseed = $request->getParsedBody();
            $payload = $builder->withValidationReseed($reseed)
                               ->withValidationError($result->getErrors())
                               ->withCode(400)
                               ->build();
            return $response->withJson($payload, 400);
        }
    }


    public function sources_delete(Request $request, Response $response, array $args = []): Response {
        try { 
          $this->db->where('c_uid', (int)$args['uid']);
          $this->db->delete('t_calendar_sources');
        } catch (Exception $e) { 
          throw new HttpInternalServerErrorException($request, $e->getMessage());  
        }
        $builder = new JsonResponseBuilder('calendar.sources', 1);
        $req = $request->getParsedBody();
        $req['user'] = (int)$_SESSION['core_user_id'];
        $req['id'] = (int)$args['uid'];
        $payload = $builder->withData((array)$req)->withCode(200)->build();
        return $response->withJson($payload, 200);
    }
}
