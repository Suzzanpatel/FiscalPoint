# FiscalPoint shortcuts. Run from the repository root.
PYTHON ?= python3

.PHONY: help install test test-python lint-php train train-db api serve seed

help:
	@echo "install     composer + pip dependencies"
	@echo "test        python tests + php syntax check"
	@echo "train       train models on synthetic data"
	@echo "train-db    train on the MySQL database (+ synthetic augmentation)"
	@echo "api         start the ML service (127.0.0.1:5001)"
	@echo "serve       PHP development server (127.0.0.1:8080)"
	@echo "seed        regenerate database/seed_demo.sql"

install:
	composer install
	$(PYTHON) -m pip install -r ml/requirements.txt

test: test-python lint-php

test-python:
	cd ml && $(PYTHON) -m pytest -q

lint-php:
	@find web -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true

train:
	cd ml && $(PYTHON) -m fiscalpoint_ml.train

train-db:
	cd ml && $(PYTHON) -m fiscalpoint_ml.train --source db --augment-synthetic

api:
	cd ml && $(PYTHON) -m fiscalpoint_ml.api

serve:
	php -S 127.0.0.1:8080 -t web

seed:
	cd ml && $(PYTHON) -m fiscalpoint_ml.data.seed_sql
