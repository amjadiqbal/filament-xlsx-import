<?php

return [

    'file' => [
        'placeholder' => 'Upload a spreadsheet (:types)',
        'type' => 'The file must be one of: :types.',
        'xls' => 'Old .xls workbooks cannot be read. Save the file as .xlsx (Excel: File > Save As > Excel Workbook) and upload it again.',
        'unreadable' => 'This file could not be read as a spreadsheet. Check that it is a valid .xlsx, .ods or .csv file.',
    ],

    'sheet' => [
        'label' => 'Sheet',
        'missing' => 'The workbook has no visible sheet ":sheet". Sheets are matched by name, or by position counted from 0; hidden sheets are skipped.',
    ],

    'example' => [
        'label' => 'Download example .xlsx file',
    ],

];
