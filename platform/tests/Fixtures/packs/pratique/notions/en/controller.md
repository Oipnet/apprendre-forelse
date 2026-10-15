---
title: 'Symfony controller: from request to response'
---
A controller receives the `Request` and returns a `Response`: it is the function the router calls for a given address. The arguments it declares (a route parameter, a header, the JSON body) reach it already converted.
