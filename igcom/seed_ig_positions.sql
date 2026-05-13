-- Seed dummy IG positions for account_id=2
-- api_key: fff812b74240535da18c298438a83ce2ce0148ec
-- uni_id (ig_accounts.uni_id): 25293010-a2b7-4e1d-8e04-e2c3350c8118

INSERT INTO `ig_positions`
  (`account_id`, `api_key`, `epic`, `ticker`, `deal_id`, `direction`, `size`, `level`, `stop_level`, `limit_level`, `unrealized_pnl`, `currency`, `strategy`)
VALUES
  (2, 'fff812b74240535da18c298438a83ce2ce0148ec', 'CS.D.EURUSD.CFD.IP',  'EURUSD',  'DIAAAA-SEED-EURUSD-001', 'BUY',  1.000000, 1.08750000, 1.08000000, 1.10000000,   12.50, 'USD', 'Volume_Momentum'),
  (2, 'fff812b74240535da18c298438a83ce2ce0148ec', 'CS.D.GBPUSD.CFD.IP',  'GBPUSD',  'DIAAAA-SEED-GBPUSD-002', 'SELL', 0.500000, 1.27300000, 1.28000000, 1.26000000,   -4.20, 'USD', 'Reversal'),
  (2, 'fff812b74240535da18c298438a83ce2ce0148ec', 'CS.D.USDJPY.CFD.IP',  'USDJPY',  'DIAAAA-SEED-USDJPY-003', 'BUY',  0.750000, 154.21500000, 153.50000000, 156.00000000, 22.80, 'USD', 'Trend_Follow'),
  (2, 'fff812b74240535da18c298438a83ce2ce0148ec', 'IX.D.SPTRD.IFE.IP',   'SPX500',  'DIAAAA-SEED-SPX500-004', 'BUY',  0.100000, 5285.40000000, 5200.00000000, 5400.00000000,  8.10, 'USD', 'Volume_Momentum'),
  (2, 'fff812b74240535da18c298438a83ce2ce0148ec', 'CS.D.CFEGOLD.CFE.IP', 'XAUUSD',  'DIAAAA-SEED-XAUUSD-005', 'SELL', 0.200000, 2348.10000000, 2375.00000000, 2300.00000000, -3.45, 'USD', 'Mean_Revert');
