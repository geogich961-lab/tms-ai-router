# Update transport compatibility

TMS AI Router uses a query-action fallback on `/` for Update Center so hot updates continue to work on Nginx/TMS OS installations where nested admin API paths may be rewritten unexpectedly.
