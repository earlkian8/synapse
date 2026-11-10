"""Behavioural indicators of emotional states, built from what the survey and the ERP
already record.

The survey asked no question about feelings, and the ERP holds none. What both hold is
behaviour, and organisational research reads some behaviour as a sign of how a person
feels about their job. Each indicator here names that reading, the theory behind it,
and the recorded inputs it is built from. An indicator **indicates** a state; it does
not establish it, and the screens say so.

=====================  ==============================  =====================================
indicator              built from                      reads as
=====================  ==============================  =====================================
withdrawal             absences + late arrivals        emotional disengagement
career stagnation      time since promotion, ever      career frustration
workload strain        overtime                        emotional exhaustion (burnout)
effort–reward          overtime against pay and        feeling under-rewarded
imbalance              promotion
=====================  ==============================  =====================================

Three more are read from what only an organisation's own records hold (ADR 0102),
so the general model, which learned from a survey that never asked them, cannot read
them; an organisation's own model can, once it graduates, and keeps them only when they
make it more accurate on that organisation's records:

=====================  ==============================  =====================================
team mood              the department's latest shown   the mood around a person
                       check-in result
recognition gap        months since an award or kudos  feeling unrecognised
rest                   leave taken over the last year  strain from too little rest
=====================  ==============================  =====================================

Which of them the score reads was decided by the data (notebook §5), not by the theory:

* **withdrawal** is a model input (:data:`.features.INDICATORS`), the equal-weight mean of
  the standardised absence and lateness bands. It is one reason in an explanation.
* **career stagnation** is read through its two inputs, which the model takes as they
  are; the ratio form (share of tenure since the last promotion) added nothing to them.
  In the survey the people never promoted left *less* often than those who were, so its
  level is reported with the leave rate the data shows, whichever way it points.
* **workload strain** and **effort–reward imbalance** need overtime, and the share who
  left is flat across its bands; as inputs they made the score less accurate. They are
  reported as readings with that reason, never as a reason for a score.

Each reading carries its level, the survey's leave rate at that level (``profile`` /
``reading``) and, for an indicator the score reads, the risk points it is responsible
for — its Shapley value, or the sum of its inputs' (:mod:`synapse_ml.explain`).

:class:`CandidateIndicators` builds the model-input forms notebook §5 compared, so the
comparison and the readings rest on one definition of each indicator.
"""

from __future__ import annotations

from collections.abc import Callable, Mapping
from dataclasses import dataclass
from typing import Any

import numpy as np
import pandas as pd
from sklearn.base import BaseEstimator, TransformerMixin

from .features import BAND_EDGES, band_of

# Each indicator's thresholds, in survey bands (see features.BAND_LABELS).
_HEAVY_OVERTIME = band_of("overtime_hours_90d", 26)  # more than 25 hours in 90 days
_SOME_OVERTIME = band_of("overtime_hours_90d", 11)  # 11 to 25 hours
_LOW_PAY = band_of("monthly_salary", 24_999)  # under ₱25,000 a month
_LONG_WAIT = band_of("years_since_promotion", 3)  # three years or more since a promotion


def _recorded(value: Any) -> bool:
    return value is not None and not (isinstance(value, float) and np.isnan(value))


def _band(record: Mapping[str, Any], feature: str) -> int | None:
    value = record.get(feature)
    return band_of(feature, float(value)) if _recorded(value) else None


def _withdrawal(record: Mapping[str, Any]) -> int | None:
    """None · Low · Moderate · High, on the two attendance bands added together (0–8).
    Both inputs are needed: one count alone says nothing about the other."""
    absences, lates = _band(record, "absences_90d"), _band(record, "lates_90d")
    if absences is None or lates is None:
        return None
    total = absences + lates
    return 0 if total == 0 else 1 if total <= 2 else 2 if total <= 4 else 3


def _stagnation(record: Mapping[str, Any]) -> int | None:
    """Progressing · Not yet promoted · Plateaued · Stalled: whether there was a promotion,
    and whether the wait since it (or since joining) has reached three years."""
    wait, ever = _band(record, "years_since_promotion"), record.get("ever_promoted")
    if wait is None or not _recorded(ever):
        return None
    long_wait = wait >= _LONG_WAIT
    if float(ever) > 0:
        return 2 if long_wait else 0
    return 3 if long_wait else 1


def _workload(record: Mapping[str, Any]) -> int | None:
    """None · Normal · High · Very high, on overtime hours in the last 90 days."""
    overtime = _band(record, "overtime_hours_90d")
    if overtime is None:
        return None
    return 0 if overtime == 0 else 1 if overtime < _HEAVY_OVERTIME else 2 if overtime == _HEAVY_OVERTIME else 3


def _team_mood(record: Mapping[str, Any]) -> int | None:
    """Good · Steady · Strained · Low, on the department's mean mood (1–5)."""
    mood = record.get("team_mood")
    if not _recorded(mood):
        return None
    mood = float(mood)
    return 0 if mood >= 4.0 else 1 if mood >= 3.25 else 2 if mood >= 2.5 else 3


def _recognition_gap(record: Mapping[str, Any]) -> int | None:
    """Recent · Within a year · Over a year · Over two years, since the last award or kudos."""
    months = record.get("months_since_recognition")
    if not _recorded(months):
        return None
    months = float(months)
    return 0 if months < 6 else 1 if months < 12 else 2 if months < 24 else 3


def _rest(record: Mapping[str, Any]) -> int | None:
    """Regular · Little · None taken, on approved leave days over the last year."""
    days = record.get("leave_days_365d")
    if not _recorded(days):
        return None
    days = float(days)
    return 0 if days >= 5 else 1 if days > 0 else 2


def _effort_reward(record: Mapping[str, Any]) -> int | None:
    """Balanced · Some strain · Imbalanced: overtime (effort) against pay under ₱25,000 or
    three years or more without a promotion (reward). Unknown, not "balanced", when
    the effort is there but no reward input is on record."""
    overtime = _band(record, "overtime_hours_90d")
    if overtime is None:
        return None
    if overtime < _SOME_OVERTIME:
        return 0
    pay, wait = _band(record, "monthly_salary"), _band(record, "years_since_promotion")
    shortfalls = [band <= _LOW_PAY for band in [pay] if band is not None] + [
        band >= _LONG_WAIT for band in [wait] if band is not None
    ]
    if any(shortfalls):
        return 2 if overtime >= _HEAVY_OVERTIME else 1
    if pay is None or wait is None:
        return None
    return 0


@dataclass(frozen=True)
class Indicator:
    key: str
    label: str
    # The emotional state the behaviour is read as.
    indicates: str
    theory: str
    inputs: tuple[str, ...]
    # Least to most of the state.
    levels: tuple[str, ...]
    level: Callable[[Mapping[str, Any]], int | None]
    # Why the score does not read it, when it does not (shown with the reading).
    not_read: str | None = None
    # Only an organisation's own records hold it (ADR 0102): the general model can't read it.
    own_records: bool = False


CATALOGUE: tuple[Indicator, ...] = (
    Indicator(
        key="withdrawal",
        label="Withdrawal",
        indicates="Emotional disengagement",
        theory="Withdrawal progression: lateness and absence come before quitting "
        "(Rosse, 1988; Hanisch & Hulin, 1990, 1991; Berry, Lelchook & Clark, 2012).",
        inputs=("absences_90d", "lates_90d"),
        levels=("None", "Low", "Moderate", "High"),
        level=_withdrawal,
    ),
    Indicator(
        key="career_stagnation",
        label="Career stagnation",
        indicates="Career frustration",
        theory="Career plateau research; Herzberg's two-factor theory (advancement as a motivator).",
        inputs=("years_since_promotion", "ever_promoted"),
        levels=("Progressing", "Not yet promoted", "Plateaued", "Stalled"),
        level=_stagnation,
    ),
    Indicator(
        key="workload_strain",
        label="Workload strain",
        indicates="Emotional exhaustion (burnout)",
        theory="Job Demands–Resources model (Bakker & Demerouti, 2007).",
        inputs=("overtime_hours_90d",),
        levels=("None", "Normal", "High", "Very high"),
        level=_workload,
        not_read="In the survey, people left at about the same rate at every level of overtime, and as an "
        "input it made the score less accurate.",
    ),
    Indicator(
        key="effort_reward",
        label="Effort–reward imbalance",
        indicates="Feeling under-rewarded",
        theory="Effort–Reward Imbalance model (Siegrist, 1996).",
        inputs=("overtime_hours_90d", "monthly_salary", "years_since_promotion"),
        levels=("Balanced", "Some strain", "Imbalanced"),
        level=_effort_reward,
        not_read="It rests on overtime, which carries no signal in the survey; as an input it made the "
        "score less accurate.",
    ),
    Indicator(
        key="team_mood",
        label="Team mood",
        indicates="The mood around them",
        theory="Unit-level engagement and satisfaction predict turnover (Harter, Schmidt & Hayes, 2002); "
        "collective turnover (Heavey, Holwerda & Hausknecht, 2013).",
        inputs=("team_mood", "team_engagement", "team_leave_thoughts"),
        levels=("Good", "Steady", "Strained", "Low"),
        level=_team_mood,
        own_records=True,
    ),
    Indicator(
        key="recognition_gap",
        label="Recognition gap",
        indicates="Feeling unrecognised",
        theory="Recognition as a motivator (Herzberg's two-factor theory); recognition predicted staying "
        "the next year (Walk, Zhang & Littlepage, 2019).",
        inputs=("months_since_recognition",),
        levels=("Recent", "Within a year", "Over a year", "Over two years"),
        level=_recognition_gap,
        own_records=True,
    ),
    Indicator(
        key="rest",
        label="Rest",
        indicates="Strain from too little rest",
        theory="Effort–Recovery model: strain builds when effort is not followed by recovery "
        "(Meijman & Mulder, 1998).",
        inputs=("leave_days_365d",),
        levels=("Regular", "Little", "None taken"),
        level=_rest,
        own_records=True,
    ),
)

# Why an indicator only an organisation's own records hold is not read, by whose model.
_NOT_READ_GENERAL = ("Only your organisation's own model can learn this: the survey the general model learned "
                     "from never asked it. It is kept with every score until your model graduates.")
_NOT_READ_LOCAL = ("Your own model tried it on your records and left it out, because it did not make the "
                   "score more accurate.")

BY_KEY = {indicator.key: indicator for indicator in CATALOGUE}


def level_label(key: str, record: Mapping[str, Any]) -> str | None:
    """An indicator's level for one record, in words; None when its inputs are not on record."""
    indicator = BY_KEY[key]
    level = indicator.level(record)
    return None if level is None else indicator.levels[level]


def status(indicator: Indicator, players: Mapping[str, list[str]]) -> str:
    """How a model reads an indicator: ``"direct"`` (one of its inputs), ``"inputs"``
    (through every one of the inputs it is built from) or ``"none"``."""
    # Direct: the model reads the indicator itself, as one input built from all of its
    # own (a signal may share an indicator's name, as team_mood does: that is an input).
    if list(players.get(indicator.key, [])) == list(indicator.inputs) and len(indicator.inputs) > 1:
        return "direct"
    read = {name for inputs in players.values() for name in inputs}
    return "inputs" if set(indicator.inputs) <= read else "none"


def not_read(indicator: Indicator, players: Mapping[str, list[str]], local: bool) -> str | None:
    """Why ``indicator`` is not in this model's score, or None when it is."""
    if status(indicator, players) != "none":
        return None
    if indicator.own_records:
        return _NOT_READ_LOCAL if local else _NOT_READ_GENERAL
    return indicator.not_read


def definitions(players: Mapping[str, list[str]], local: bool = False) -> list[dict[str, Any]]:
    """Every indicator as a run's reference states it once: what it reads as, the theory,
    its inputs and levels, and whether this model reads it (``local``: an organisation's
    own model, whose reasons for leaving one out are its own)."""
    return [
        {
            "key": ind.key,
            "label": ind.label,
            "indicates": ind.indicates,
            "theory": ind.theory,
            "inputs": list(ind.inputs),
            "levels": list(ind.levels),
            "read": status(ind, players),
            "not_read": not_read(ind, players, local),
            "own_records": ind.own_records,
        }
        for ind in CATALOGUE
    ]


def profile(records: pd.DataFrame, y) -> dict[str, list[dict[str, Any]]]:
    """Leave rates by level, for every indicator the records can place people on."""
    left = np.asarray(y, dtype=int)
    rows = records.to_dict("records")
    table: dict[str, list[dict[str, Any]]] = {}
    for ind in CATALOGUE:
        if not set(ind.inputs) <= set(records.columns):
            continue
        levels = np.array([ind.level(row) for row in rows], dtype=object)
        bands = []
        for i, name in enumerate(ind.levels):
            mask = levels == i
            people = int(mask.sum())
            if people:
                leavers = int(left[mask].sum())
                bands.append({"band": name, "people": people, "left": leavers, "rate": round(leavers / people, 4)})
        if bands:
            table[ind.key] = bands
    return table


def readings(
    record: Mapping[str, Any],
    players: Mapping[str, list[str]],
    contributions: Mapping[str, float],
    evidence: Mapping[str, Any] | None,
) -> list[dict[str, Any]]:
    """One record's indicators: each level the record can be placed on, with the risk
    points the score gives it (``None`` when the model does not read it) and the share
    who left at that level in the data the model learned from."""
    table = (evidence or {}).get("indicators") or {}
    out = []
    for ind in CATALOGUE:
        level = ind.level(record)
        if level is None:
            continue
        read = status(ind, players)
        if read == "direct":
            points = contributions.get(ind.key, 0.0)
        elif read == "inputs":
            points = sum(contributions.get(name, 0.0) for name in ind.inputs)
        else:
            points = None
        band = next((row for row in table.get(ind.key, []) if row["band"] == ind.levels[level]), None)
        out.append(
            {
                "key": ind.key,
                "level": ind.levels[level],
                "rank": level,
                "read": read,
                "points": None if points is None else round(float(points), 1),
                "inputs": {name: _plain(record.get(name)) for name in ind.inputs if _recorded(record.get(name))},
                "evidence": None if band is None else {
                    "rate": band["rate"],
                    "people": band["people"],
                    "overall_rate": (evidence or {}).get("overall_rate"),
                },
            }
        )
    return out


def _plain(value: Any) -> Any:
    return value.item() if isinstance(value, np.generic) else value


# ---- the model-input forms notebook §5 compared ----------------------------------------

CANDIDATES = {
    "workload_strain": "the standardised overtime band",
    "effort_reward": "the standardised overtime band minus the mean of the standardised pay band and the "
    "negated standardised years-since-promotion band",
    "career_stagnation": "the share of tenure since the last promotion (1 when never promoted)",
    "pay_inequity": "the log of pay over the median pay of the person's department group",
}


class CandidateIndicators(BaseEstimator, TransformerMixin):
    """Adds the named candidate indicators (``CANDIDATES``) to a frame as numeric columns,
    for the comparison in notebook §5. Everything it learns — each band's mean and
    spread, each department group's median pay — comes from the rows it is fitted on,
    so inside cross-validation nothing leaks from the rows a fold is scored on.
    ``pay_inequity`` needs a ``department_group`` column (``survey.clean`` adds it)."""

    def __init__(self, add: tuple[str, ...] = ()):
        self.add = add

    def fit(self, X: pd.DataFrame, y=None):
        unknown = set(self.add) - set(CANDIDATES)
        if unknown:
            raise ValueError(f"unknown candidate indicators: {sorted(unknown)}")
        bands = self._bands(X)
        self.median_ = bands.median()
        bands = bands.fillna(self.median_)
        self.mean_, self.spread_ = bands.mean(), bands.std(ddof=0).replace(0, 1)
        if "pay_inequity" in self.add:
            pay = pd.to_numeric(X["monthly_salary"], errors="coerce")
            self.department_pay_ = pay.groupby(X["department_group"]).median().to_dict()
            self.pay_ = float(pay.median())
        return self

    def transform(self, X: pd.DataFrame) -> pd.DataFrame:
        out = X.copy()
        bands = self._bands(X).fillna(self.median_)
        z = (bands - self.mean_) / self.spread_
        if "workload_strain" in self.add:
            out["workload_strain"] = z["overtime_hours_90d"].to_numpy()
        if "effort_reward" in self.add:
            reward = (z["monthly_salary"] - z["years_since_promotion"]) / 2
            out["effort_reward"] = (z["overtime_hours_90d"] - reward).to_numpy()
        if "career_stagnation" in self.add:
            tenure = pd.to_numeric(X["tenure_years"], errors="coerce").clip(lower=0.25)
            wait = pd.to_numeric(X["years_since_promotion"], errors="coerce")
            out["career_stagnation"] = (wait / tenure).clip(0, 1).fillna(1.0).to_numpy()
        if "pay_inequity" in self.add:
            peers = X["department_group"].map(self.department_pay_).astype(float).fillna(self.pay_)
            pay = pd.to_numeric(X["monthly_salary"], errors="coerce")
            out["pay_inequity"] = np.log((pay / peers).fillna(1.0)).to_numpy()
        return out

    @staticmethod
    def _bands(X: pd.DataFrame) -> pd.DataFrame:
        columns = ["overtime_hours_90d", "monthly_salary", "years_since_promotion"]
        out = pd.DataFrame(index=X.index)
        for name in columns:
            values = pd.to_numeric(X[name], errors="coerce") if name in X else pd.Series(np.nan, index=X.index)
            out[name] = np.where(values.isna(), np.nan, np.digitize(values.fillna(0), BAND_EDGES[name]))
        return out
