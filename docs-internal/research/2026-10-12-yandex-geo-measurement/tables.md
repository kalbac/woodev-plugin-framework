## Per settlement

| key | group | class | correct geo_id | forms found (position) | old-plugin pick correct (forms) |
|---|---|---|---|---|---|
| arkhangelsk | problem | city | 20 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| drozhzhino-mo | problem | small place | 121363 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| drozhzhino-smolensk | problem | small place | 98958 | A:3, A0:3, B:3, C:3, D:-, E:3, F:3, G:3, G2:3, H:3 | 0/10 |
| irkutsk | problem | city | 63 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| kaliningrad | problem | city | 22 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| krasnogorsk | control | city | 10735 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| lyubertsy | problem | city | 10738 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| mikhailovka-volgograd | problem | city | 10965 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| moscow | control | city | 213 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| oktyabrsky-bashkortostan | problem | city | 20235 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| oktyabrsky-mo | problem | small place | 105574 | A:0, A0:0, B:0, C:0, D:-, E:0, F:0, G:0, G2:0, H:0 | 9/10 |
| pushkino-city | problem | city | 10748 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| pushkino-village-domodedovo | problem | small place | None | A:-, A0:-, B:-, C:-, D:-, E:-, F:-, G:-, G2:-, H:- | 0/10 |
| saint-petersburg | control | city | 2 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| samara | problem | city | 51 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| sochi | problem | city | 239 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| timashevsk | problem | city | 21141 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| tolyatti | control | city | 240 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| village-berezovka-krasnoyarsk | control | small place | None | A:-, A0:-, B:-, C:-, D:-, E:-, F:-, G:-, G2:-, H:- | 0/10 |
| village-ozerki-tver | control | small place | 141496 | A:1, A0:1, B:1, C:1, D:-, E:1, F:1, G:1, G2:1, H:1 | 0/10 |
| village-shilovo-ryazan | control | small place | None | A:-, A0:-, B:-, C:-, D:-, E:-, F:-, G:-, G2:-, H:- | 0/10 |
| village-sosnovka-perm | control | small place | None | A:-, A0:-, B:-, C:-, D:-, E:-, F:-, G:-, G2:-, H:- | 0/10 |
| vladimirskaya-stanitsa | problem | small place | 131459 | A:0, A0:0, B:0, C:0, D:-, E:0, F:0, G:0, G2:0, H:0 | 9/10 |
| vladivostok | problem | city | 75 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| volzhsk | problem | city | 20721 | A:3, A0:3, B:3, C:3, D:0, E:3, F:3, G:3, G2:3, H:3 | 1/10 |
| voronezh | problem | city | 193 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| yakutsk | problem | city | 74 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |
| yekaterinburg | control | city | 54 | A:0, A0:0, B:0, C:0, D:0, E:0, F:0, G:0, G2:0, H:0 | 10/10 |

## Form success rates (denominator = all 28 settlements; a none_found place counts as not found)

| form | string | found: all | at pos 0: all | found: city | found: small | pos 0: city | pos 0: small | old-plugin correct: all |
|---|---|---|---|---|---|---|---|---|
| A | country-region-city | 24/28 | 21/28 | 19/19 | 5/9 | 18/19 | 3/9 | 21/28 |
| A0 | country-city-region | 24/28 | 21/28 | 19/19 | 5/9 | 18/19 | 3/9 | 21/28 |
| B | country-no-commas | 24/28 | 21/28 | 19/19 | 5/9 | 18/19 | 3/9 | 21/28 |
| C | country-regionshort-city | 24/28 | 21/28 | 19/19 | 5/9 | 18/19 | 3/9 | 21/28 |
| D | city-only | 20/28 | 20/28 | 19/19 | 1/9 | 19/19 | 1/9 | 20/28 |
| E | regionshort-city | 24/28 | 21/28 | 19/19 | 5/9 | 18/19 | 3/9 | 21/28 |
| F | country-region-area-typed | 24/28 | 21/28 | 19/19 | 5/9 | 18/19 | 3/9 | 21/28 |
| G | dadata-value | 24/28 | 21/28 | 19/19 | 5/9 | 18/19 | 3/9 | 21/28 |
| G2 | dadata-unrestricted | 24/28 | 21/28 | 19/19 | 5/9 | 18/19 | 3/9 | 21/28 |
| H | city-region | 24/28 | 21/28 | 19/19 | 5/9 | 18/19 | 3/9 | 21/28 |

## Point lists: geo_id (A) vs bounding boxes (B), counts by point id

| key | |A| | bbox | |B| | |A∩B| | B\A | B\A name share | A\B | A\B median km | A\B max km |
|---|---|---|---|---|---|---|---|---|---|
| arkhangelsk | 32 | ±0.05° | 19 | 19 | 0 | None | 13 | 9.31 | 13.64 |
| arkhangelsk | 32 | ±0.1° | 30 | 29 | 1 | 0.0 | 3 | 12.86 | 13.64 |
| arkhangelsk | 32 | ±0.2° | 39 | 32 | 7 | 0.0 | 0 | None | None |
| drozhzhino-mo | 9 | ±0.05° | 122 | 9 | 113 | 0.0 | 0 | None | None |
| drozhzhino-mo | 9 | ±0.1° | 436 | 9 | 427 | 0.0 | 0 | None | None |
| drozhzhino-mo | 9 | ±0.2° | 1563 | 9 | 1554 | 0.0 | 0 | None | None |
| drozhzhino-smolensk | 17 | ±0.05° | 0 | 0 | 0 | None | 17 | 23.21 | 31.02 |
| drozhzhino-smolensk | 17 | ±0.1° | 0 | 0 | 0 | None | 17 | 23.21 | 31.02 |
| drozhzhino-smolensk | 17 | ±0.2° | 5 | 5 | 0 | None | 12 | 23.51 | 31.02 |
| irkutsk | 70 | ±0.05° | 55 | 55 | 0 | None | 15 | 6.75 | 10.9 |
| irkutsk | 70 | ±0.1° | 71 | 70 | 1 | 0.0 | 0 | None | None |
| irkutsk | 70 | ±0.2° | 79 | 70 | 9 | 0.0 | 0 | None | None |
| kaliningrad | 79 | ±0.05° | 77 | 72 | 5 | 0.0 | 7 | 10.04 | 12.78 |
| kaliningrad | 79 | ±0.1° | 99 | 79 | 20 | 0.0 | 0 | None | None |
| kaliningrad | 79 | ±0.2° | 105 | 79 | 26 | 0.0 | 0 | None | None |
| krasnogorsk | 63 | ±0.05° | 135 | 61 | 74 | 0.0 | 2 | 5.76 | 5.9 |
| krasnogorsk | 63 | ±0.1° | 433 | 63 | 370 | 0.003 | 0 | None | None |
| krasnogorsk | 63 | ±0.2° | 1774 | 63 | 1711 | 0.001 | 0 | None | None |
| lyubertsy | 79 | ±0.05° | 214 | 79 | 135 | 0.0 | 0 | None | None |
| lyubertsy | 79 | ±0.1° | 659 | 79 | 580 | 0.0 | 0 | None | None |
| lyubertsy | 79 | ±0.2° | 1895 | 79 | 1816 | 0.0 | 0 | None | None |
| mikhailovka-volgograd | 14 | ±0.05° | 16 | 14 | 2 | 1.0 | 0 | None | None |
| mikhailovka-volgograd | 14 | ±0.1° | 16 | 14 | 2 | 1.0 | 0 | None | None |
| mikhailovka-volgograd | 14 | ±0.2° | 16 | 14 | 2 | 1.0 | 0 | None | None |
| moscow | 2276 | ±0.05° | 258 | 258 | 0 | None | 2018 | 13.82 | 61.9 |
| moscow | 2276 | ±0.1° | 1016 | 1016 | 0 | None | 1260 | 16.48 | 61.9 |
| moscow | 2276 | ±0.2° | 2732 | 2027 | 705 | 0.001 | 249 | 32.97 | 61.9 |
| oktyabrsky-bashkortostan | 36 | ±0.05° | 36 | 36 | 0 | None | 0 | None | None |
| oktyabrsky-bashkortostan | 36 | ±0.1° | 38 | 36 | 2 | 0.0 | 0 | None | None |
| oktyabrsky-bashkortostan | 36 | ±0.2° | 75 | 36 | 39 | 0.0 | 0 | None | None |
| oktyabrsky-mo | 10 | ±0.05° | 68 | 10 | 58 | 0.017 | 0 | None | None |
| oktyabrsky-mo | 10 | ±0.1° | 323 | 10 | 313 | 0.026 | 0 | None | None |
| oktyabrsky-mo | 10 | ±0.2° | 1357 | 10 | 1347 | 0.006 | 0 | None | None |
| pushkino-city | 35 | ±0.05° | 58 | 35 | 23 | 0.0 | 0 | None | None |
| pushkino-city | 35 | ±0.1° | 239 | 35 | 204 | 0.0 | 0 | None | None |
| pushkino-city | 35 | ±0.2° | 901 | 35 | 866 | 0.0 | 0 | None | None |
| pushkino-village-domodedovo | 0 | ±0.05° | 22 | 0 | 22 | 0.0 | 0 | None | None |
| pushkino-village-domodedovo | 0 | ±0.1° | 64 | 0 | 64 | 0.0 | 0 | None | None |
| pushkino-village-domodedovo | 0 | ±0.2° | 391 | 0 | 391 | 0.0 | 0 | None | None |
| saint-petersburg | 1071 | ±0.05° | 255 | 255 | 0 | None | 816 | 11.35 | 46.11 |
| saint-petersburg | 1071 | ±0.1° | 765 | 756 | 9 | 0.0 | 315 | 18.14 | 46.11 |
| saint-petersburg | 1071 | ±0.2° | 1147 | 987 | 160 | 0.0 | 84 | 27.28 | 46.11 |
| samara | 311 | ±0.05° | 106 | 106 | 0 | None | 205 | 9.95 | 37.03 |
| samara | 311 | ±0.1° | 280 | 264 | 16 | 0.0 | 47 | 17.45 | 37.03 |
| samara | 311 | ±0.2° | 366 | 308 | 58 | 0.0 | 3 | 36.37 | 37.03 |
| sochi | 195 | ±0.05° | 63 | 63 | 0 | None | 132 | 21.34 | 63.38 |
| sochi | 195 | ±0.1° | 90 | 90 | 0 | None | 105 | 23.35 | 63.38 |
| sochi | 195 | ±0.2° | 170 | 163 | 7 | 0.0 | 32 | 42.85 | 63.38 |
| timashevsk | 21 | ±0.05° | 21 | 21 | 0 | None | 0 | None | None |
| timashevsk | 21 | ±0.1° | 26 | 21 | 5 | 0.0 | 0 | None | None |
| timashevsk | 21 | ±0.2° | 57 | 21 | 36 | 0.0 | 0 | None | None |
| tolyatti | 124 | ±0.05° | 73 | 72 | 1 | 0.0 | 52 | 7.39 | 25.73 |
| tolyatti | 124 | ±0.1° | 124 | 120 | 4 | 0.0 | 4 | 21.48 | 25.73 |
| tolyatti | 124 | ±0.2° | 162 | 122 | 40 | 0.025 | 2 | 25.66 | 25.73 |
| village-berezovka-krasnoyarsk | 0 | ±0.05° | 0 | 0 | 0 | None | 0 | None | None |
| village-berezovka-krasnoyarsk | 0 | ±0.1° | 0 | 0 | 0 | None | 0 | None | None |
| village-berezovka-krasnoyarsk | 0 | ±0.2° | 0 | 0 | 0 | None | 0 | None | None |
| village-ozerki-tver | 2 | ±0.05° | 6 | 2 | 4 | 0.0 | 0 | None | None |
| village-ozerki-tver | 2 | ±0.1° | 10 | 2 | 8 | 0.0 | 0 | None | None |
| village-ozerki-tver | 2 | ±0.2° | 75 | 2 | 73 | 0.0 | 0 | None | None |
| village-shilovo-ryazan | 0 | ±0.05° | 0 | 0 | 0 | None | 0 | None | None |
| village-shilovo-ryazan | 0 | ±0.1° | 7 | 0 | 7 | 0.0 | 0 | None | None |
| village-shilovo-ryazan | 0 | ±0.2° | 14 | 0 | 14 | 0.0 | 0 | None | None |
| village-sosnovka-perm | 0 | ±0.05° | 0 | 0 | 0 | None | 0 | None | None |
| village-sosnovka-perm | 0 | ±0.1° | 0 | 0 | 0 | None | 0 | None | None |
| village-sosnovka-perm | 0 | ±0.2° | 1 | 0 | 1 | 0.0 | 0 | None | None |
| vladimirskaya-stanitsa | 0 | ±0.05° | 0 | 0 | 0 | None | 0 | None | None |
| vladimirskaya-stanitsa | 0 | ±0.1° | 11 | 0 | 11 | 0.0 | 0 | None | None |
| vladimirskaya-stanitsa | 0 | ±0.2° | 28 | 0 | 28 | 0.0 | 0 | None | None |
| vladivostok | 26 | ±0.05° | 12 | 12 | 0 | None | 14 | 8.09 | 20.03 |
| vladivostok | 26 | ±0.1° | 22 | 22 | 0 | None | 4 | 16.94 | 20.03 |
| vladivostok | 26 | ±0.2° | 26 | 26 | 0 | None | 0 | None | None |
| volzhsk | 15 | ±0.05° | 15 | 15 | 0 | None | 0 | None | None |
| volzhsk | 15 | ±0.1° | 32 | 15 | 17 | 0.0 | 0 | None | None |
| volzhsk | 15 | ±0.2° | 45 | 15 | 30 | 0.0 | 0 | None | None |
| voronezh | 314 | ±0.05° | 189 | 189 | 0 | None | 125 | 7.71 | 36.29 |
| voronezh | 314 | ±0.1° | 317 | 304 | 13 | 0.0 | 10 | 14.79 | 36.29 |
| voronezh | 314 | ±0.2° | 373 | 312 | 61 | 0.0 | 2 | 36.26 | 36.29 |
| yakutsk | 0 | ±0.05° | 0 | 0 | 0 | None | 0 | None | None |
| yakutsk | 0 | ±0.1° | 0 | 0 | 0 | None | 0 | None | None |
| yakutsk | 0 | ±0.2° | 0 | 0 | 0 | None | 0 | None | None |
| yekaterinburg | 371 | ±0.05° | 219 | 219 | 0 | None | 152 | 8.15 | 22.4 |
| yekaterinburg | 371 | ±0.1° | 359 | 355 | 4 | 0.0 | 16 | 16.75 | 22.4 |
| yekaterinburg | 371 | ±0.2° | 439 | 370 | 69 | 0.0 | 1 | 22.4 | 22.4 |

## Networks inside A (geo_id list)

| key | |A| | by operator_id | by type | yandex_branded | market_partner | name share |
|---|---|---|---|---|---|---|
| arkhangelsk | 32 | {'5post': 13, 'market_l4g': 19} | {'pickup_point': 32} | 19 | 19 | 1.0 |
| drozhzhino-mo | 9 | {'market_l4g': 9} | {'pickup_point': 9} | 9 | 9 | 1.0 |
| drozhzhino-smolensk | 17 | {'5post': 7, 'market_l4g': 10} | {'pickup_point': 17} | 10 | 10 | 0.0 |
| irkutsk | 70 | {'5post': 23, 'market_l4g': 47} | {'pickup_point': 70} | 47 | 47 | 1.0 |
| kaliningrad | 79 | {'5post': 79} | {'pickup_point': 79} | 0 | 0 | 1.0 |
| krasnogorsk | 63 | {'5post': 11, 'market_l4g': 52} | {'pickup_point': 63} | 49 | 52 | 1.0 |
| lyubertsy | 79 | {'market_l4g': 69, '5post': 10} | {'pickup_point': 79} | 69 | 69 | 1.0 |
| mikhailovka-volgograd | 14 | {'5post': 7, 'market_l4g': 7} | {'pickup_point': 14} | 6 | 7 | 1.0 |
| moscow | 2276 | {'market_l4g': 1777, '5post': 499} | {'pickup_point': 2276} | 1741 | 1777 | 0.873 |
| oktyabrsky-bashkortostan | 36 | {'5post': 19, 'market_l4g': 17} | {'pickup_point': 36} | 16 | 17 | 1.0 |
| oktyabrsky-mo | 10 | {'5post': 5, 'market_l4g': 5} | {'pickup_point': 10} | 5 | 5 | 1.0 |
| pushkino-city | 35 | {'5post': 10, 'market_l4g': 25} | {'pickup_point': 35} | 23 | 25 | 1.0 |
| pushkino-village-domodedovo | 0 | {} | {} | 0 | 0 | None |
| saint-petersburg | 1071 | {'5post': 178, 'market_l4g': 893} | {'pickup_point': 1071} | 855 | 893 | 0.828 |
| samara | 311 | {'5post': 74, 'market_l4g': 237} | {'pickup_point': 310, 'terminal': 1} | 230 | 236 | 0.971 |
| sochi | 195 | {'5post': 75, 'market_l4g': 120} | {'pickup_point': 195} | 107 | 120 | 0.754 |
| timashevsk | 21 | {'market_l4g': 10, '5post': 11} | {'pickup_point': 21} | 9 | 10 | 1.0 |
| tolyatti | 124 | {'market_l4g': 91, '5post': 33} | {'pickup_point': 124} | 91 | 91 | 0.992 |
| village-berezovka-krasnoyarsk | 0 | {} | {} | 0 | 0 | None |
| village-ozerki-tver | 2 | {'5post': 1, 'market_l4g': 1} | {'pickup_point': 2} | 0 | 1 | 1.0 |
| village-shilovo-ryazan | 0 | {} | {} | 0 | 0 | None |
| village-sosnovka-perm | 0 | {} | {} | 0 | 0 | None |
| vladimirskaya-stanitsa | 0 | {} | {} | 0 | 0 | None |
| vladivostok | 26 | {'market_l4g': 26} | {'pickup_point': 26} | 26 | 26 | 1.0 |
| volzhsk | 15 | {'5post': 7, 'market_l4g': 8} | {'pickup_point': 15} | 5 | 8 | 1.0 |
| voronezh | 314 | {'5post': 70, 'market_l4g': 244} | {'pickup_point': 313, 'terminal': 1} | 242 | 243 | 0.997 |
| yakutsk | 0 | {} | {} | 0 | 0 | None |
| yekaterinburg | 371 | {'market_l4g': 273, '5post': 98} | {'pickup_point': 370, 'terminal': 1} | 261 | 272 | 0.97 |

## DaData ground truth

| key | group | DaData value | fias_id | kladr_id | geo_lat, geo_lon | region_with_type | area | type | parent city |
|---|---|---|---|---|---|---|---|---|---|
| arkhangelsk | problem | г Архангельск | 06814fb6-0dc3-4bec-ba20-11f894a0faf5 | 2900000100000 | 64.539393, 40.516939 | Архангельская обл | - | г / город | - |
| drozhzhino-mo | problem | Московская обл, г Видное, пгт Дрожжино | 33179b59-639a-4db0-817c-97f0e6d23bb4 | 5000002503500 | 55.527677, 37.593821 | Московская обл | - | пгт / поселок городского типа | Видное |
| drozhzhino-smolensk | problem | Смоленская обл, Вяземский р-н, деревня Дрожжино | 7d36a70e-54fa-4d78-975d-1e7be8a97626 | 6700300008200 | 55.414915, 34.280286 | Смоленская обл | Вяземский р-н | д / деревня | - |
| irkutsk | problem | г Иркутск | 8eeed222-72e7-47c3-ab3a-9a553c31cf72 | 3800000300000 | 52.286387, 104.28066 | Иркутская обл | - | г / город | - |
| kaliningrad | problem | г Калининград | df679694-d505-4dd3-b514-4ba48c8a97d8 | 3900000100000 | 54.70739, 20.507307 | Калининградская обл | - | г / город | - |
| krasnogorsk | control | Московская обл, г Красногорск | 63fcf18a-365e-451f-baee-8d09ac50b773 | 5000004900000 | 55.831812, 37.329429 | Московская обл | - | г / город | - |
| lyubertsy | problem | Московская обл, г Люберцы | 427b4e19-f221-4abc-8904-81f7409c1ce3 | 5005400000000 | 55.676499, 37.898125 | Московская обл | г Люберцы | г / город | - |
| mikhailovka-volgograd | problem | Волгоградская обл, г Михайловка | f02dbf6d-57ad-49bc-8011-603bf7126bb5 | 3400000400000 | 50.070928, 43.240083 | Волгоградская обл | - | г / город | - |
| moscow | control | г Москва | 0c5b2444-70a0-4932-980c-b4dc0d3f02b5 | 7700000000000 | 55.75396, 37.620393 | г Москва | - | г / город | - |
| oktyabrsky-bashkortostan | problem | респ Башкортостан, г Октябрьский | abd1bc35-ec51-437a-abee-76a4f620f662 | 0200000400000 | 54.481448, 53.46557 | респ Башкортостан | - | г / город | - |
| oktyabrsky-mo | problem | Московская обл, г Люберцы, пгт Октябрьский | 3557d22e-dd5a-4783-a4fb-4b1059af9a5f | 5005400002000 | 55.610977, 37.973727 | Московская обл | г Люберцы | пгт / поселок городского типа | Люберцы |
| pushkino-city | problem | Московская обл, г Пушкино | 7f22cfa4-34fa-4fa0-bcb0-620cd8402cfa | 5005000000000 | 56.010428, 37.847155 | Московская обл | г Пушкино | г / город | - |
| pushkino-village-domodedovo | problem | Московская обл, г Домодедово, деревня Пушкино | 8d0327de-091f-41e5-8082-8b021b698f9a | 5000000123800 | 55.3717, 37.856991 | Московская обл | - | д / деревня | Домодедово |
| saint-petersburg | control | г Санкт-Петербург | c2deb16a-0330-4f05-821f-1d09c93331e6 | 7800000000000 | 59.939084, 30.315879 | г Санкт-Петербург | - | г / город | - |
| samara | problem | г Самара | bb035cc3-1dc2-4627-9d25-a1bf2d4b936b | 6300000100000 | 53.1951774, 50.1069625 | Самарская обл | - | г / город | - |
| sochi | problem | Краснодарский край, г Сочи | 79da737a-603b-4c19-9b54-9114c96fb912 | 2300000700000 | 43.585525, 39.723062 | Краснодарский край | - | г / город | - |
| timashevsk | problem | Краснодарский край, г Тимашевск | f09d518d-5617-476f-8c76-877899375202 | 2303400100000 | 45.615896, 38.935086 | Краснодарский край | Тимашевский р-н | г / город | - |
| tolyatti | control | Самарская обл, г Тольятти | 242e87c1-584d-4360-8c4c-aae2fe90048e | 6300000700000 | 53.5205667, 49.3894799 | Самарская обл | - | г / город | - |
| village-berezovka-krasnoyarsk | control | Красноярский край, Абанский р-н, село Березовка | 788a7676-e0e6-40f1-8201-20720184926b | 2400200000600 | 56.616036, 95.632938 | Красноярский край | Абанский р-н | с / село | - |
| village-ozerki-tver | control | Тверская обл, Конаковский р-н, поселок Озерки | 1e512564-dbfa-41b1-a88b-08a7b20048fb | 6901500011700 | 56.643545, 36.213595 | Тверская обл | Конаковский р-н | п / поселок | - |
| village-shilovo-ryazan | control | Рязанская обл, Кораблинский р-н, деревня Шилово | 5a2e8a30-01b3-4529-a288-6836ae0aacea | 6200700011700 | 54.014067, 39.899185 | Рязанская обл | Кораблинский р-н | д / деревня | - |
| village-sosnovka-perm | control | Пермский край, Березовский р-н, деревня Сосновка | d7cb6883-45fe-4ab8-aaee-7fcf8c5ed9b1 | 5900300014900 | 57.448419, 57.618877 | Пермский край | Березовский р-н | д / деревня | - |
| vladimirskaya-stanitsa | problem | Краснодарский край, Лабинский р-н, ст-ца Владимирская | 85825f48-3d48-403d-9379-b94d8a9719fb | 2302100000800 | 44.545654, 40.788275 | Краснодарский край | Лабинский р-н | ст-ца / станица | - |
| vladivostok | problem | г Владивосток | 7b6de6a5-86d0-4735-b11a-499081111af8 | 2500000100000 | 43.116391, 131.882421 | Приморский край | - | г / город | - |
| volzhsk | problem | респ Марий Эл, г Волжск | f183b079-b65f-4781-9b79-310ba29b724d | 1200000200000 | 55.862333, 48.371574 | респ Марий Эл | - | г / город | - |
| voronezh | problem | г Воронеж | 5bf5ddff-6353-4a3d-80c4-6fb27f00c6c1 | 3600000100000 | 51.659306, 39.196854 | Воронежская обл | - | г / город | - |
| yakutsk | problem | г Якутск | 884c84a2-0141-4652-962d-8a92989b88f7 | 1400000100000 | 62.028098, 129.732555 | респ Саха (Якутия) | - | г / город | - |
| yekaterinburg | control | г Екатеринбург | 2763c110-cb8b-416a-9dac-ad28a55b4402 | 6600000100000 | 56.838607, 60.605514 | Свердловская обл | - | г / город | - |
