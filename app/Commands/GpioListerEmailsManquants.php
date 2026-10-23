<?php

namespace App\Commands;

/**
 * Alias CLI du diagnostic détaillé des comptes chefs restés sans email.
 */
class GpioListerEmailsManquants extends GpioInvestiguerEmailsManquants
{
    protected $name = 'gpio:lister-emails-manquants';
    protected $description = 'Liste localement les comptes chefs restés sans email.';
    protected $usage = 'gpio:lister-emails-manquants [--tenant=1]';
}
