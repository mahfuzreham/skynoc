# SkyNoc WHMCS License Provisioning Module

This module lets a SkyNoc reseller use their WHMCS installation to create SkyNoc license orders.

## Flow

1. Reseller registers on SkyNoc.
2. Reseller deposits the required activation amount and gets wallet credit after admin approval.
3. Admin creates license packages and prices in SkyNoc.
4. Reseller creates a scoped API key with:
   - packages:read
   - orders:create
   - orders:read
5. Install this module in WHMCS under:
   modules/servers/skynoclicense/
6. Configure a WHMCS product with the SkyNoc Package ID and reseller API key.
7. When the WHMCS product is provisioned, the module calls:
   POST /api/v1/orders
8. SkyNoc deducts the package price from the reseller wallet and creates a pending fulfillment order.
9. SkyNoc admin assigns an available license and completes the order.

The upstream WHMCS provider account is never exposed to the reseller or stored in the module.

## Important

This is the first provisioning version. Cancellation, suspension and package-change actions intentionally do not alter the SkyNoc license yet; they can be connected to dedicated SkyNoc order/license lifecycle endpoints later.
