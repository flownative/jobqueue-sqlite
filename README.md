[![MIT license](http://img.shields.io/badge/license-MIT-brightgreen.svg)](http://opensource.org/licenses/MIT)
![Packagist][packagist]
[packagist]: https://img.shields.io/packagist/v/flownative/jobqueue-sqlite.svg

# Sqlite implementation for Flowpack Job Queue

This [Flow](https://flow.typo3.org) package provides an Sqlite implementation for the Flowpack Job Queue.
NOTE: This package is in an early alpha stage. It may work fine, but there is not enough real-world experience with it yet.

## Installation

This adaptor is installed as a regular Flow package via Composer. For your existing project, simply include
`flownative/jobqueue-sqlite` into the dependencies of your Flow or Neos distribution:

```bash
    $ composer require flownative/jobqueue-sqlite:@dev
```

## Configuration

See `Configuration/Settings.yaml` in this package for possible configuration options.
