Search API plugin for Algolia
==============

### Travis build status
[![Build Status](https://travis-ci.org/fdmweb/search_api_algolia.svg?branch=master)](https://travis-ci.org/fdmweb/search_api_algolia)

CONTENTS OF THIS FILE
---------------------
 * Introduction
 * Requirements
 * Installation
 * Known problems
 * Maintainers
 * Dependencies


INTRODUCTION
------------
This module provides integration with the Algolia service
(https://www.algolia.com), through Drupal's Search API. This module is intended
to be used by developers, as it does not currently provide any implementation of
an actual search interface. Only indexing is currently supported. As a result,
enabling that module will not have any visible effect on your application.
Search functionality may be implemented using the Algolia Javascript API.

Currently supported:
 * initial indexing of entities (see "Know problems")
 * re-indexing on node updates or through forced re-index action

Type of fields which have been successfully tested:
 * entity reference fields (either single or multivalued)
 * standard text fields containing either strings or integers (integer support
   is important for comparison functions in the search query)
 * geofield, address field components


REQUIREMENTS
------------
This module requires the following modules:
 * Search API (https://www.drupal.org/project/search_api)

This module also uses the following library:
 * Algolia search client PHP library
   https://github.com/algolia/algoliasearch-client-php
   add via `composer require algolia/algoliasearch-client-php`


INSTALLATION
------------
Please refer to the INSTALL.txt file.


KNOWN PROBLEMS
--------------
Please report problems.


MAINTAINERS
-----------

Current D7 maintainers:
Matthieu Bergel (mbrgl) - https://www.drupal.org/user/489214

Current D8 maintainers:
Jens Beltofte (beltofte) - https://www.drupal.org/u/beltofte
Christopher Calip (chriscalip) - https://www.drupal.org/u/chriscalip

The initial Drupal 7 development of this module has been sponsored by TroisCube (http://www.troiscube.com).
The initial Drupal 8 development of this module has been sponsored by FDM.dk and FFWagency.com

The module is also at drupal.org in a older version here https://www.drupal.org/project/search_api_algolia

DEPENDENCIES
------------
This module requires an account on https://www.algolia.com, where you will be
able to generate the required Application ID and API key. A 14-day free trial
period is granted with every new account.

Pricing options can be found on the Algolia website:
https://www.algolia.com/pricing
