<?php
use Glued\Fin\Controllers\FinController;
use Glued\Core\Middleware\RedirectGuests;
use Glued\Core\Middleware\RestrictGuests;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

// Define the app routes.
$app->group('/api/fin/v1', function (RouteCollectorProxy $group) {
    $group->get ('/accounts[/{uid:[0-9]+}]', FinController::class . ':accounts_list')->setName('fin.accounts.api01'); 
    $group->post('/accounts[/{uid:[0-9]+}]', FinController::class . ':accounts_post');
    $group->patch('/accounts[/{uid:[0-9]+}]', FinController::class . ':accounts_patch');
    $group->delete('/accounts[/{uid:[0-9]+}]', FinController::class . ':accounts_delete');
    $group->get ('/accounts/sync[/{uid:[0-9]+}[/{from:[12]\d{3}\-\d{2}\-\d{2}}[/{to:[12]\d{3}\-\d{2}\-\d{2}}]]]', FinController::class . ':accounts_sync')->setName('fin.accounts.sync.api01');
    $group->get ('/trx[/{uid:[0-9]+}]', FinController::class . ':trx_list')->setName('fin.trx.api01'); 
    $group->post ('/trx[/{uid:[0-9]+}]', FinController::class . ':trx_post');
    $group->patch('/trx[/{uid:[0-9]+}]', FinController::class . ':trx_patch');
    $group->delete('/trx[/{uid:[0-9]+}]', FinController::class . ':trx_delete');
    $group->get ('/costs[/{uid:[0-9]+}]', FinController::class . ':costs_list')->setName('fin.costs.api01'); 
    $group->post ('/costs[/{uid:[0-9]+}]', FinController::class . ':costs_post');
    $group->patch('/costs[/{uid:[0-9]+}]', FinController::class . ':costs_patch');
    $group->delete('/costs[/{uid:[0-9]+}]', FinController::class . ':costs_delete');
})->add(RestrictGuests::class);

$app->group('/fin', function (RouteCollectorProxy $group) {
    $group->get ('/trx', FinController::class . ':trx_list_ui')->setName('fin.trx'); 
    $group->get ('/accounts', FinController::class . ':accounts_list_ui')->setName('fin.accounts'); 
    $group->get ('/costs', FinController::class . ':costs_list_ui')->setName('fin.costs'); 
})->add(RedirectGuests::class);

