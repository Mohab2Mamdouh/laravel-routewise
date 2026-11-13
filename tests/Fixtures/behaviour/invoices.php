<?php

use Routewise\Behaviour\Scenario;

return [
    Scenario::prompt('Delete invoice 42')->named('deletes by id')->expects('invoice_delete', ['id' => 42]),
    Scenario::prompt('Which invoices are unpaid?')->expects('invoices_list', ['status' => 'unpaid'])->never('invoice_delete'),
    Scenario::prompt('Tell me a joke')->expectsNoTool(),
];
