const fs = require('fs');
const source = fs.readFileSync('modules/expert/feature-estimation.php', 'utf8');
const start = source.lastIndexOf('<script>');
const end = source.lastIndexOf('</script>');
new Function(source.slice(start + 8, end).replace(/<\?php[\s\S]*?\?>/g, 'null'));
process.stdout.write('JavaScript syntax OK\n');
