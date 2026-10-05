# Yii Schedule Change Log

## 1.0.0 under development

- New #1: Add scheduling core: triggers (cron, periodic, callback), schedules with mutex and stateful checkpoints, missed-run catch-up, and `schedule:run`, `schedule:work` and `schedule:list` commands (@KalimeroMK)
- Bug #1: Fix checkpoint position moving backwards when a re-discovered run is skipped (@KalimeroMK)
- Bug #1: Fix PHP 8.1 compatibility and falsable date arithmetic (@KalimeroMK)
- Initial release.
