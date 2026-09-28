# SkyNoc API v1

Base:
`https://reseller.skynoc.net/api/v1/`

Authentication:
`Authorization: Bearer YOUR_API_KEY`

## Get licenses
GET `/licenses`

## Get reissue history
GET `/reissues`

## Request reissue
POST `/licenses/{license_id}/reissue`

JSON:
```json
{"new_domain":"new.example.com","reason":"Server migration"}
```

A request creates a pending internal request. It does not automatically call an upstream provider. Admin/staff completes the manual upstream process and marks the request completed.
