-- Restrict the IEM catalogue to the shortlist in EqualizeMe-IEM-List.docx.
--
-- ONE STATEMENT. No temporary tables, no CREATE, no multi-step batch.
--
-- The earlier version of this script built a helper table and ran twelve
-- statements. On shared hosting that produced "#2006 MySQL server has gone
-- away" -- the host closed the connection part way through the batch. A
-- single statement cannot be interrupted half-done, and needs no privilege
-- beyond DELETE, so it survives hosts that cut long sessions short.
--
-- The shortlist is inlined below. Names are normalised on both sides:
-- lowercased, filler words dropped, then everything that is not a letter or
-- a digit removed. That is how "AFUL Acoustics Performer 5 + 2" in the
-- document matches brand "Aful Acoustics" + name "Performer 5+2" in the
-- table, and why this stays correct after any future import.
--
-- BACK UP FIRST. There is no backup table here, deliberately -- creating one
-- is what made the script fragile. Use phpMyAdmin instead:
--
--   1. click the `iems` table
--   2. Export tab -> Go
--   3. save the .sql file somewhere you will find it again
--
-- That file restores the table completely if you change your mind.
--
-- Run DRY-RUN FIRST: sql/prune_iems_dryrun.sql tells you what this removes.

DELETE FROM iems
WHERE REGEXP_REPLACE(REGEXP_REPLACE(LOWER(CONCAT(COALESCE(brand,''),' ',name)),
    '(acoustics|acoustic labs|audio|labs)',' '),'[^a-z0-9]','')
      NOT IN (
    '7hzsalnotesxcrinaclezero2', '7hzsalnoteszero', '7hztimeless', '7hztimeless2',
    '7hzxcrinaclediablo', '7hzxcrinacledivine', 'afuldawnx', 'afulexplorer', 'afulperformer5',
    'afulperformer52', 'afulperformer8', 'afulperformer8s', 'appleairpodspro',
    'appleairpodspro2', 'appleairpodspro3', 'binaryep321mems', 'binaryxgizchopin',
    'crineardaybreak', 'crinearnightfall', 'crinearreference', 'dunufalconpro',
    'dunufalconultra', 'dunutitans', 'dunutitans2', 'dunutitanx', 'dunuvulkan', 'dunuvulkan2',
    'dunuxgizdavinci', 'elysianpilgrim', 'etymoticer2se', 'etymoticer2xr', 'etymoticer4xr',
    'fatfreqxhbbdeuce', 'fiiofh3', 'hifigokefinedelci', 'ibassoit01s', 'iosogno', 'kefinedelci',
    'kefinekleansv', 'kineracelestwyvernabyss', 'kineracelestwyvernqing', 'kinerafreya',
    'kiwiearsaether', 'kiwiearsastral', 'kiwiearsbelle', 'kiwiearscadenza', 'kiwiearske4',
    'kiwiearsorchestra', 'kiwiearsorchestra2', 'kiwiearsorchestralite', 'kiwiearsquartet',
    'kiwiearsquintet', 'kiwiearsxbmediachorus', 'kiwiearsxcrinaclesingolo', 'letshouerastralis',
    'letshouercadenza4', 'letshouerd13', 'letshouerdz4', 'letshouers08', 'letshouers12',
    'letshouers12ultra', 'letshouers15', 'letshouersinger', 'letshouerxgizgalileo',
    'letshuoerastralis', 'letshuoercadenza4', 'letshuoerd13', 'letshuoerdz4', 'letshuoers08',
    'letshuoers12', 'letshuoers12ultra', 'letshuoers15', 'letshuoersinger',
    'letshuoerxgizgalileo', 'mezealba', 'moondroparia2', 'moondropariasnowedition',
    'moondropblessing2', 'moondropblessing3', 'moondropblessing3aqua', 'moondropchu',
    'moondropchu2', 'moondropchu3', 'moondropconcerto', 'moondropdroplet', 'moondropgoldenages',
    'moondropgoldenages2', 'moondropharmon', 'moondropjiu', 'moondropkadenz', 'moondroplan',
    'moondroplan2pop', 'moondroplan2ref', 'moondropmarigold', 'moondropmay', 'moondropmoca',
    'moondropnekocake', 'moondroppudding', 'moondropquark2', 'moondropquarks',
    'moondropquarks2', 'moondropquarksdsp', 'moondroprays', 'moondropspacetravel2',
    'moondropsparks', 'moondropssp', 'moondropssr', 'moondropstarfield2', 'moondropstellaris',
    'moondropthedroplet', 'moondropultrasonic', 'moondropvariations', 'moondropvoyager',
    'moondropxcrinacleblessing2dusk', 'moondropxcrinacledusk', 'moondropxcrinaclesilicon',
    'moondropxhonkaistarrailrobins', 'ooooopusxop22', 'ooooopusxop24', 'ooopusxop22',
    'ooopusxop24', 'opusxop22', 'opusxop24', 'sennheiserie200', 'sennheiserie300',
    'sennheiserie400pro', 'sennheiserie600', 'sennheiserie900', 'sennheisermomentumtw2',
    'sennheisermomentumtw3', 'shurese215', 'shurese215pro', 'simgotea1000', 'simgotea500',
    'simgotea500lm', 'simgotem6l', 'simgotsupermix4', 'simgotsupermix5', 'sivganightingale',
    'sivgaque', 'softearsedge', 'softearsstudio2', 'softearsstudio4', 'softearsvolumes',
    'sonywf1000xm3', 'sonywf1000xm4', 'sonywf1000xm5', 'tanchjim4u', 'tanchjimbunny',
    'tanchjimdarling', 'tanchjimecho', 'tanchjimfission', 'tanchjimfola', 'tanchjimhana',
    'tanchjimhana2021', 'tanchjimkara', 'tanchjimola', 'tanchjimone', 'tanchjimorigin',
    'tanchjimoxygen', 'tanchjimsoda', 'tanchjimtanya', 'tanchjimzero', 'tangzuwaner',
    'tangzuwanersgiiredlion', 'tangzuwuheydayedition', 'tangzuwuzetian', 'thieelixir',
    'thiehype2', 'thiehype4', 'thiehype4mk2', 'thielegacy2', 'thielegacy4', 'thielegacy5',
    'trutheargate', 'truthearhexa', 'truthearhola', 'truthearnova', 'truthearpure',
    'truthearxcrinaclezero', 'truthearxcrinaclezeroblue2', 'truthearxcrinaclezerored',
    'xennsmangirdtea', 'xennsmangirdtea2', 'xennsmangridtea', 'xennsmangridtea2', 'xennsteapro',
    'xennsteaprose', 'ziigaatarcanis', 'ziigaatcrescent', 'ziigaathorizon', 'ziigaatluna',
    'ziigaatlush', 'ziigaatodyssey', 'ziigaatxhangoutodyssey2', 'ziigaatxjayestrella'
      )
  AND REGEXP_REPLACE(REGEXP_REPLACE(LOWER(name),
    '(acoustics|acoustic labs|audio|labs)',' '),'[^a-z0-9]','')
      NOT IN (
    '7hzsalnotesxcrinaclezero2', '7hzsalnoteszero', '7hztimeless', '7hztimeless2',
    '7hzxcrinaclediablo', '7hzxcrinacledivine', 'afuldawnx', 'afulexplorer', 'afulperformer5',
    'afulperformer52', 'afulperformer8', 'afulperformer8s', 'appleairpodspro',
    'appleairpodspro2', 'appleairpodspro3', 'binaryep321mems', 'binaryxgizchopin',
    'crineardaybreak', 'crinearnightfall', 'crinearreference', 'dunufalconpro',
    'dunufalconultra', 'dunutitans', 'dunutitans2', 'dunutitanx', 'dunuvulkan', 'dunuvulkan2',
    'dunuxgizdavinci', 'elysianpilgrim', 'etymoticer2se', 'etymoticer2xr', 'etymoticer4xr',
    'fatfreqxhbbdeuce', 'fiiofh3', 'hifigokefinedelci', 'ibassoit01s', 'iosogno', 'kefinedelci',
    'kefinekleansv', 'kineracelestwyvernabyss', 'kineracelestwyvernqing', 'kinerafreya',
    'kiwiearsaether', 'kiwiearsastral', 'kiwiearsbelle', 'kiwiearscadenza', 'kiwiearske4',
    'kiwiearsorchestra', 'kiwiearsorchestra2', 'kiwiearsorchestralite', 'kiwiearsquartet',
    'kiwiearsquintet', 'kiwiearsxbmediachorus', 'kiwiearsxcrinaclesingolo', 'letshouerastralis',
    'letshouercadenza4', 'letshouerd13', 'letshouerdz4', 'letshouers08', 'letshouers12',
    'letshouers12ultra', 'letshouers15', 'letshouersinger', 'letshouerxgizgalileo',
    'letshuoerastralis', 'letshuoercadenza4', 'letshuoerd13', 'letshuoerdz4', 'letshuoers08',
    'letshuoers12', 'letshuoers12ultra', 'letshuoers15', 'letshuoersinger',
    'letshuoerxgizgalileo', 'mezealba', 'moondroparia2', 'moondropariasnowedition',
    'moondropblessing2', 'moondropblessing3', 'moondropblessing3aqua', 'moondropchu',
    'moondropchu2', 'moondropchu3', 'moondropconcerto', 'moondropdroplet', 'moondropgoldenages',
    'moondropgoldenages2', 'moondropharmon', 'moondropjiu', 'moondropkadenz', 'moondroplan',
    'moondroplan2pop', 'moondroplan2ref', 'moondropmarigold', 'moondropmay', 'moondropmoca',
    'moondropnekocake', 'moondroppudding', 'moondropquark2', 'moondropquarks',
    'moondropquarks2', 'moondropquarksdsp', 'moondroprays', 'moondropspacetravel2',
    'moondropsparks', 'moondropssp', 'moondropssr', 'moondropstarfield2', 'moondropstellaris',
    'moondropthedroplet', 'moondropultrasonic', 'moondropvariations', 'moondropvoyager',
    'moondropxcrinacleblessing2dusk', 'moondropxcrinacledusk', 'moondropxcrinaclesilicon',
    'moondropxhonkaistarrailrobins', 'ooooopusxop22', 'ooooopusxop24', 'ooopusxop22',
    'ooopusxop24', 'opusxop22', 'opusxop24', 'sennheiserie200', 'sennheiserie300',
    'sennheiserie400pro', 'sennheiserie600', 'sennheiserie900', 'sennheisermomentumtw2',
    'sennheisermomentumtw3', 'shurese215', 'shurese215pro', 'simgotea1000', 'simgotea500',
    'simgotea500lm', 'simgotem6l', 'simgotsupermix4', 'simgotsupermix5', 'sivganightingale',
    'sivgaque', 'softearsedge', 'softearsstudio2', 'softearsstudio4', 'softearsvolumes',
    'sonywf1000xm3', 'sonywf1000xm4', 'sonywf1000xm5', 'tanchjim4u', 'tanchjimbunny',
    'tanchjimdarling', 'tanchjimecho', 'tanchjimfission', 'tanchjimfola', 'tanchjimhana',
    'tanchjimhana2021', 'tanchjimkara', 'tanchjimola', 'tanchjimone', 'tanchjimorigin',
    'tanchjimoxygen', 'tanchjimsoda', 'tanchjimtanya', 'tanchjimzero', 'tangzuwaner',
    'tangzuwanersgiiredlion', 'tangzuwuheydayedition', 'tangzuwuzetian', 'thieelixir',
    'thiehype2', 'thiehype4', 'thiehype4mk2', 'thielegacy2', 'thielegacy4', 'thielegacy5',
    'trutheargate', 'truthearhexa', 'truthearhola', 'truthearnova', 'truthearpure',
    'truthearxcrinaclezero', 'truthearxcrinaclezeroblue2', 'truthearxcrinaclezerored',
    'xennsmangirdtea', 'xennsmangirdtea2', 'xennsmangridtea', 'xennsmangridtea2', 'xennsteapro',
    'xennsteaprose', 'ziigaatarcanis', 'ziigaatcrescent', 'ziigaathorizon', 'ziigaatluna',
    'ziigaatlush', 'ziigaatodyssey', 'ziigaatxhangoutodyssey2', 'ziigaatxjayestrella'
      );
