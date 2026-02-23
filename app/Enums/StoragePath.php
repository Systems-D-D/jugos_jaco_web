<?php

namespace App\Enums;

enum StoragePath: string
{
    case CLIENTS_PROFILE_IMAGE = 'clients/images/profile';
    case CLIENTS_BUSINESS_IMAGES = 'clients/images/business';
    case CLIENTS_EQUIPMENT_IMAGES = 'clients/images/equipment';
    case EMPLOYEES_PROFILE_IMAGE = 'employees/images/profile';
    case BRANCHES_IMAGES = 'branches/images';
    case PRODUCTS_IMAGES = 'products/images';
    case PRODUCTS_IMAGES_TEMP = 'products/temp';
    case ROOT_DIRECTORY = 'public';
}
