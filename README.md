# funkytime/yuki
A php connector for Yuki's Sales and Accounting API (subsets), intended to create Sales Invoices and get back payment status.

```php
$invoice = [
    'Reference' => '',
    // ...
    'Contact' => [
        'ContactCode' => '',
        // ...
    ]
    'ContactPerson' => ['FullName' => ''],
    'InvoiceLines' => [
        'InvoiceLine' => [
            'ProductQuantity' => '',
            'Product' => [
                'Description' => '',
                // ...
            ]
        ]
    ]
    ];
];
$YukiSales = new \FunkyTime\Yuki($api_key, 'sales');
$YukiSales->ProcessInvoice($invoice);
```

```php
$YukiAccounting = new \FunkyTime\Yuki($api_key, 'accounting');
$status = $YukiAccounting->GetInvoiceBalance($inv_reference);
// Result: an array with keys 'openAmount' and 'originalAmount' 
```

## Region (NL / BE)

Yuki uses separate API hosts per country. Pass the region as the third constructor argument:

- Belgian customers: `api.yukiworks.be` (default)
- Dutch customers: `api.yukiworks.nl`

```php
$region = \FunkyTime\Yuki::regionFromVat($ownerVat) ?? \FunkyTime\Yuki::REGION_BE;

$YukiSales = new \FunkyTime\Yuki($api_key, 'sales', $region);
$YukiAccounting = new \FunkyTime\Yuki($api_key, 'accounting', $region);
```

When VAT is unavailable, store the region alongside the API key in your application.
