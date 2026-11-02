<?php
pm_Loader::registerAutoload();
pm_Context::init('dondominio-dns');
Modules_DondominioDns_Runner::retry();
