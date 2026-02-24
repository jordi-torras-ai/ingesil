git status
git add .
echo "commit comment:"
read MESSAGE
git commit -m "$MESSAGE"
git push origin main
