<?php declare(strict_types=1);

namespace PreConselho\Controllers;

use Shared\Http\Response;
use Shared\Support\View;

final class PortalController
{
    public function __construct(private readonly View $view) {}

    public function dashboard(): Response
    {
        return new Response($this->view->render('portal',['title'=>'Gestão Pedagógica']));
    }
}
