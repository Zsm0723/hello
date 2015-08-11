#!/usr/bin/perl
#
# DNSSEC proper resolution

use lib '/opt/zabbix/scripts';

use strict;
use warnings;
use RSM;
use RSMSLV;
use TLD_constants qw(:ec);

my $cfg_key_in = 'rsm.dns.udp.rtt[';
my $cfg_key_out = 'rsm.slv.dnssec.avail';

parse_avail_opts();
exit_if_running();

set_slv_config(get_rsm_config());

db_connect();

my $interval = get_macro_dns_udp_delay();
my $cfg_minonline = get_macro_dns_probe_online();
my $probe_avail_limit = get_macro_probe_avail_limit();

my $clock = (opt('from') ? getopt('from') : time());
my $period = (opt('period') ? getopt('period') : 1);

my $tlds_ref = get_tlds();

while ($period > 0)
{
	my ($from, $till, $value_ts) = get_interval_bounds($interval, $clock);

	$period -= $interval / 60;
	$clock += $interval;

	my $probes_ref = get_online_probes($from, $till, $probe_avail_limit, undef);
	my $probes_count = scalar(@$probes_ref);

	init_values();

	foreach (@$tlds_ref)
	{
		$tld = $_;

		my $lastclock = get_lastclock($tld, $cfg_key_out);
		fail("configuration error: item \"$cfg_key_out\" not found at host \"$tld\"") if ($lastclock == E_FAIL);
		next if (check_lastclock($lastclock, $value_ts, $interval) != SUCCESS);

		if ($probes_count < $cfg_minonline)
		{
			push_value($tld, $cfg_key_out, $value_ts, UP, "Up (not enough probes online, $probes_count while $cfg_minonline required)");
			add_alert(ts_str($value_ts) . "#system#zabbix#$cfg_key_out#PROBLEM#$tld (not enough probes online, $probes_count while $cfg_minonline required)") if (alerts_enabled() == SUCCESS);
			next;
		}

		my $hostids_ref = probes2tldhostids($tld, $probes_ref);
		if (scalar(@$hostids_ref) == 0)
		{
			wrn("no probe hosts found");
			next;
		}

		my $items_ref = get_items_by_hostids($hostids_ref, $cfg_key_in, 0); # incomplete key
		if (scalar(@$items_ref) == 0)
		{
			wrn("no items ($cfg_key_in) found");
			next;
		}

		my $values_ref = get_values_by_items($items_ref, $from, $till);
		if (scalar(@$values_ref) == 0)
		{
			wrn("no item values ($cfg_key_in) found");
			next;
		}

		info("  itemid:", $_->[0], " value:", $_->[1]) foreach (@$values_ref);

		my $probes_with_results = get_probes_count($items_ref, $values_ref);
		if ($probes_with_results < $cfg_minonline)
		{
			push_value($tld, $cfg_key_out, $value_ts, UP, "Up (not enough probes with reults, $probes_with_results while $cfg_minonline required)");
			add_alert(ts_str($value_ts) . "#system#zabbix#$cfg_key_out#PROBLEM#$tld (not enough probes with reults, $probes_with_results while $cfg_minonline required)") if (alerts_enabled() == SUCCESS);
			next;
		}

		my $success_values = scalar(@$values_ref);
		foreach (@$values_ref)
		{
			$success_values-- if (ZBX_EC_DNS_NS_ERRSIG == $_->[1]);
		}

		my $test_result = DOWN;
		my $total_values = scalar(@$values_ref);
		my $perc = $success_values * 100 / $total_values;
		$test_result = UP if ($perc > SLV_UNAVAILABILITY_LIMIT);

		push_value($tld, $cfg_key_out, $value_ts, $test_result, avail_result_msg($test_result, $success_values, $total_values, $perc, $value_ts));
	}

	# unset TLD (for the logs)
	$tld = undef;

	send_values();
}

slv_exit(SUCCESS);

sub get_values_by_items
{
	my $items_ref = shift;
	my $from = shift;
	my $till = shift;

	my $items_str = "";
	foreach (@$items_ref)
	{
		$items_str .= "," if ("" ne $items_str);
		$items_str .= $_->{'itemid'};
	}

	my $rows_ref = db_select("select itemid,value from history where itemid in ($items_str) and clock between $from and $till");

	my @values;
	foreach my $row_ref (@$rows_ref)
	{
		push(@values, [$row_ref->[0], $row_ref->[1]]);
	}

	return \@values;
}

sub get_probes_count
{
	my $items_ref = shift;
	my $values_ref = shift;

	my @hostids;
	foreach my $value_ref (@$values_ref)
	{
		foreach my $item_ref (@$items_ref)
		{
			if ($value_ref->[0] == $item_ref->{'itemid'})
			{
				push(@hostids, $item_ref->{'hostid'}) unless ($item_ref->{'hostid'} ~~ @hostids);
				last;
			}
		}
	}

	return scalar(@hostids);
}
